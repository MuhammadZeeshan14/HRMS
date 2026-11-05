<?php

namespace App\Services\LeaveAttendance;

use App\Models\Employee;
use App\Models\LeaveAttendance\PunchEvent;
use App\Models\Location;
use App\Models\User;
use App\Services\EmployeeAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PunchService
{
    public function office(Employee $employee): ?Location
    {
        // Reporting office is the explicit office assignment; location is the legacy fallback.
        $office = Location::find($employee->reporting_location_id ?: $employee->location_id);

        return $office && (! $office->company_id || (int) $office->company_id === (int) $employee->company_id) ? $office : null;
    }

    public function configured(?Location $office): bool
    {
        return $office && $office->status === 'active' && $office->latitude !== null && $office->longitude !== null
            && is_numeric($office->latitude) && abs((float) $office->latitude) <= 90
            && is_numeric($office->longitude) && abs((float) $office->longitude) <= 180;
    }

    public function distance(float $lat, float $lng, float $officeLat, float $officeLng): float
    {
        $a = sin(deg2rad($officeLat - $lat) / 2) ** 2
            + cos(deg2rad($lat)) * cos(deg2rad($officeLat)) * sin(deg2rad($officeLng - $lng) / 2) ** 2;

        return round(6371000 * 2 * asin(sqrt(min(1, max(0, $a)))), 2);
    }

    public function record(User $actor, array $input): PunchEvent
    {
        $data = Validator::make($input, [
            'action' => 'required|in:check_in,check_out', 'submission_id' => 'required|uuid',
            'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'required|numeric|min:0|max:10000000',
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            // Lock before any consistent reads: MySQL REPEATABLE READ must not
            // establish an old snapshot while waiting behind another punch.
            $employee = Employee::where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $access = app(EmployeeAccess::class);
            abort_unless($actor && $access->enabled($actor) && ! $access->admin($actor), 403);
            $existing = PunchEvent::where('submission_id', $data['submission_id'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->employee_id === $employee->id, 403);
                if ($existing->action !== $data['action']) {
                    throw ValidationException::withMessages(['submission_id' => 'This submission was already saved for a different action. Reload before recording another punch.']);
                }

                return $existing;
            }
            $office = $this->office($employee);
            if (! $this->configured($office)) {
                throw ValidationException::withMessages(['location' => 'Your assigned office has no active, configured coordinates. Ask an Admin to set your reporting office and its latitude/longitude in Locations, then retry.']);
            }
            $now = now(config('app.timezone'));
            $event = PunchEvent::create($data + [
                'employee_id' => $employee->id, 'recorded_at' => $now->toDateTimeString(), 'attendance_date' => $now->toDateString(),
                'office_location_id' => $office->id, 'office_latitude' => $office->latitude, 'office_longitude' => $office->longitude,
                'distance_metres' => $this->distance($data['latitude'], $data['longitude'], $office->latitude, $office->longitude), 'created_at' => $now,
            ]);
            $version = app(AttendanceService::class)->commitLivePunch($employee, $actor, $event);
            $event->update(['attendance_version_id' => $version->id]);

            return $event;
        }, 3);
    }
}
