<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveAttendance\AttendanceVersion;
use App\Models\LeaveAttendance\CorrectionRequest;
use App\Models\LeaveAttendance\PunchEvent;
use App\Services\EmployeeAccess;
use App\Services\LeaveAttendance\AttendanceService;
use App\Services\LeaveAttendance\PunchService;
use App\Services\LeaveAttendance\Register;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class EmployeeAttendanceController extends Controller
{
    private function employee(Request $request): Employee
    {
        $access = app(EmployeeAccess::class);
        abort_unless($access->enabled($request->user()) && ! $access->admin($request->user()), 403);
        $employee = $access->employee($request->user());
        abort_if($request->filled('employee_id') && $request->integer('employee_id') !== $employee->id, 403);

        return $employee->loadMissing(['company', 'department', 'designation', 'primaryReport']);
    }

    public function today(Request $request, AttendanceService $attendance, PunchService $punches, Register $register)
    {
        $employee = $this->employee($request);
        $today = now(config('app.timezone'))->toDateString();
        $office = $punches->office($employee);
        $configured = $punches->configured($office);
        $current = $attendance->latest($employee->id, $today);
        $schedule = $attendance->schedule($employee->id, $today);
        $events = PunchEvent::where('employee_id', $employee->id)->where('attendance_date', $today)->orderByDesc('recorded_at')->orderByDesc('id')->get();
        $recentRows = array_reverse($register->rows(collect([$employee]), now()->subDays(6)->toDateString(), $today));

        return view('leave-attendance.employee.today', compact('employee', 'today', 'office', 'configured', 'current', 'schedule', 'events', 'recentRows'));
    }

    public function punch(Request $request, PunchService $punches)
    {
        // No employee/date/time from the client enters the service.
        $event = $punches->record($request->user(), $request->only(['action', 'submission_id', 'latitude', 'longitude', 'accuracy']));

        return response()->json(['message' => ($event->action === 'check_in' ? 'Check In' : 'Check Out').' saved at '.$event->recorded_at.' ('.config('app.timezone').').',
            'event' => $event->only(['id', 'action', 'recorded_at', 'attendance_date', 'latitude', 'longitude', 'accuracy', 'distance_metres', 'office_location_id'])]);
    }

    public function history(Request $request, Register $register)
    {
        $employee = $this->employee($request);
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $to = $request->input('to', now()->toDateString());
        $from = $request->input('from', now()->subDays(30)->toDateString());
        abort_if($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 92, 422, 'Choose a date range of at most 93 days.');
        $rows = array_reverse($register->rows(collect([$employee]), $from, $to));
        $requests = CorrectionRequest::with('decision.actor')->where('employee_id', $employee->id)->whereBetween('shift_date', [$from, $to])->orderByDesc('id')->get();
        $versions = AttendanceVersion::with('actor')->where('employee_id', $employee->id)->whereBetween('shift_date', [$from, $to])->orderByDesc('id')->get();
        $events = PunchEvent::where('employee_id', $employee->id)->whereBetween('attendance_date', [$from, $to])->orderByDesc('recorded_at')->orderByDesc('id')->get();

        return view('leave-attendance.employee.history', compact('employee', 'from', 'to', 'rows', 'requests', 'versions', 'events'));
    }
}
