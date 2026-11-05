<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveAttendance\AttendanceVersion;
use App\Models\LeaveAttendance\CorrectionRequest;
use App\Models\LeaveAttendance\PunchEvent;
use App\Models\LeaveAttendance\Shift;
use App\Models\LeaveAttendance\ShiftAssignment;
use App\Models\Location;
use App\Models\User;
use App\Services\LeaveAttendance\AttendanceService;
use App\Services\LeaveAttendance\PunchService;
use App\Services\LeaveAttendance\Register;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\EmployeeAccountFixtures;
use Tests\TestCase;

class EmployeeLiveAttendanceTest extends TestCase
{
    use EmployeeAccountFixtures, RefreshDatabase;

    private Employee $employee;

    private Location $office;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Karachi']);
        $this->travelTo(Carbon::parse('2026-11-04 09:00:00', 'Asia/Karachi'));
        $company = Company::create(['code' => 'LIVE', 'name' => 'Live Company', 'status' => 'active']);
        $this->hr = $this->linkedHr($company);
        $root = $this->hr->employee->department;
        $leaf = Department::create(['code' => 'LIVE-LEAF', 'name' => 'Employee branch', 'company_id' => $company->id, 'parent_department_id' => $root->id, 'status' => 'active']);
        $this->office = Location::create(['code' => 'OFFICE', 'name' => 'Karachi office', 'company_id' => $company->id, 'status' => 'active', 'latitude' => 24.8607, 'longitude' => 67.0011]);
        $this->employee = Employee::create(['employee_no' => 'LIVE-WORKER', 'first_name' => 'Live', 'last_name' => 'Worker', 'company_id' => $company->id, 'department_id' => $leaf->id, 'primary_report_to_id' => $this->hr->employee->id, 'reporting_location_id' => $this->office->id, 'date_of_joining' => '2026-01-01', 'employment_status' => 'active']);
        $shift = Shift::create(['company_id' => $company->id, 'name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'overnight' => false]);
        ShiftAssignment::create(['employee_id' => $this->employee->id, 'shift_id' => $shift->id, 'starts_on' => '2026-01-01', 'actor_id' => $this->hr->id]);
        $this->actingAs($this->employee->user);
    }

    private function payload(string $action = 'check_in'): array
    {
        return ['action' => $action, 'submission_id' => (string) Str::uuid(), 'latitude' => 24.86, 'longitude' => 67.0, 'accuracy' => 12.5];
    }

    private function correction(string $date = '2026-11-03'): array
    {
        return ['shift_date' => $date, 'time_in' => $date.' 09:00:00', 'time_out' => $date.' 17:00:00', 'base_version_id' => null, 'reason_type' => 'missed_punch', 'reason' => 'Previous day missed'];
    }

    public function test_multiple_punches_preserve_all_events_and_update_one_effective_daily_record(): void
    {
        foreach ([['09:00:00', 'check_in'], ['12:00:00', 'check_out'], ['13:00:00', 'check_in'], ['17:00:00', 'check_out']] as [$time,$action]) {
            $this->travelTo(Carbon::parse('2026-11-04 '.$time, 'Asia/Karachi'));
            $this->postJson(route('la.employee.punch'), $this->payload($action))->assertOk();
        }
        $this->assertDatabaseCount('hrla_punch_events', 4);
        $this->assertDatabaseCount('hrla_attendance_versions', 4);
        $attendance = app(AttendanceService::class);
        $this->assertSame(1, $attendance->effective()->count());
        $latest = $attendance->latest($this->employee->id, '2026-11-04');
        $this->assertSame('2026-11-04 09:00:00', $latest->time_in);
        $this->assertSame('2026-11-04 17:00:00', $latest->time_out);
        $this->assertDatabaseCount('hrla_correction_requests', 0);
        $this->assertSame(4, PunchEvent::whereNotNull('attendance_version_id')->count());
        $this->get(route('la.employee.today'))->assertOk()->assertSee('4 events')->assertSee('Last saved location');
    }

    public function test_retries_return_the_same_event_and_cannot_override_identity_date_or_time(): void
    {
        $data = $this->payload() + ['employee_id' => $this->hr->employee->id, 'attendance_date' => '2020-01-01', 'recorded_at' => '2020-01-01 01:00:00', 'shift_date' => '2020-01-01', 'office_location_id' => 999];
        $response = $this->postJson(route('la.employee.punch'), $data)->assertOk()->assertJsonPath('event.recorded_at', '2026-11-04 09:00:00');
        $this->travel(1)->minutes();
        $this->postJson(route('la.employee.punch'), $data)->assertOk()->assertJsonPath('event.id', $response->json('event.id'));
        $this->assertDatabaseCount('hrla_punch_events', 1);
        $this->assertDatabaseCount('hrla_attendance_versions', 1);
        $this->assertDatabaseHas('hrla_punch_events', ['employee_id' => $this->employee->id, 'office_location_id' => $this->office->id, 'attendance_date' => '2026-11-04']);
        $data['action'] = 'check_out';
        $this->postJson(route('la.employee.punch'), $data)->assertUnprocessable()->assertJsonValidationErrors('submission_id');
    }

    public function test_incomplete_and_reverse_order_punches_never_fabricate_times_or_negative_duration(): void
    {
        $this->postJson(route('la.employee.punch'), $this->payload('check_out'))->assertOk();
        $version = AttendanceVersion::firstOrFail();
        $this->assertNull($version->time_in);
        $this->assertSame('2026-11-04 09:00:00', $version->time_out);
        $this->assertNull(app(Register::class)->row($this->employee, '2026-11-04')['work_seconds']);
        $this->travel(1)->hours();
        $this->postJson(route('la.employee.punch'), $this->payload())->assertOk();
        $this->assertSame(0, app(Register::class)->row($this->employee, '2026-11-04')['work_seconds']);
        $this->travel(1)->days();
        $this->postJson(route('la.employee.punch'), $this->payload())->assertOk();
        $this->assertNull(app(AttendanceService::class)->latest($this->employee->id, '2026-11-05')->time_out);
    }

    public function test_location_validation_configuration_and_informational_distance(): void
    {
        $service = app(PunchService::class);
        $this->assertSame(0.0, $service->distance(0, 0, 0, 0));
        $this->assertEqualsWithDelta(111195, $service->distance(0, 0, 1, 0), 1);
        foreach (['latitude' => 91, 'longitude' => -181, 'accuracy' => -1] as $key => $value) {
            $this->postJson(route('la.employee.punch'), array_replace($this->payload(), [$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->postJson(route('la.employee.punch'), ['action' => 'check_in', 'submission_id' => Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude', 'accuracy']);
        $this->office->update(['latitude' => null]);
        $this->postJson(route('la.employee.punch'), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->assertDatabaseCount('hrla_punch_events', 0);
        $this->office->update(['latitude' => 24.8607]);
        $this->postJson(route('la.employee.punch'), array_replace($this->payload(), ['latitude' => 0, 'longitude' => 0]))->assertOk();
        $this->assertGreaterThan(100000, PunchEvent::first()->distance_metres);
        $this->assertEqualsWithDelta(24.8607, (float) PunchEvent::first()->office_latitude, 0.0000001);
    }

    public function test_missing_shift_rolls_back_event_and_foreign_office_is_denied(): void
    {
        ShiftAssignment::query()->delete();
        $this->postJson(route('la.employee.punch'), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('shift_date');
        $this->assertDatabaseCount('hrla_punch_events', 0);
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
        $foreign = Company::create(['code' => 'FOREIGN', 'name' => 'Foreign', 'status' => 'active']);
        $this->office->update(['company_id' => $foreign->id]);
        $this->postJson(route('la.employee.punch'), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('location');
    }

    public function test_today_future_and_blank_reason_corrections_are_denied_in_ui_and_server(): void
    {
        foreach (['2026-11-04', '2026-11-05'] as $date) {
            $this->post(route('la.attendance.store'), $this->correction($date))->assertSessionHasErrors('shift_date');
            $this->get(route('la.attendance.form', ['shift_date' => $date]))->assertRedirect()->assertSessionHasErrors('shift_date');
        }
        $this->post(route('la.attendance.store'), array_replace($this->correction(), ['reason' => '']))->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('hrla_correction_requests', 0);
        $this->get(route('la.attendance.form'))->assertOk()->assertSee('max="2026-11-03"', false);
    }

    public function test_previous_day_requests_keep_existing_approval_rule_and_original_punch_events(): void
    {
        $this->postJson(route('la.employee.punch'), $this->payload())->assertOk();
        $event = PunchEvent::first()->toArray();
        $this->travel(1)->days();
        $base = app(AttendanceService::class)->latest($this->employee->id, '2026-11-04');
        $data = array_replace($this->correction('2026-11-04'), ['base_version_id' => $base->id]);
        $this->post(route('la.attendance.store'), $data)->assertSessionHasNoErrors();
        $request = CorrectionRequest::firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertSame($base->id, app(AttendanceService::class)->latest($this->employee->id, '2026-11-04')->id);
        $this->post(route('la.attendance.store'), $data)->assertSessionHasErrors('shift_date');
        $this->post(route('la.corrections.decide', $request), ['outcome' => 'approved', 'reason' => 'Self'])->assertForbidden();
        $this->assertSame(1, $this->hr->notifications()->count());
        $this->actingAs($this->hr)->post(route('la.corrections.decide', $request), ['outcome' => 'approved', 'reason' => 'Reviewed'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame($event, PunchEvent::first()->toArray());
        $this->assertSame('2026-11-04 17:00:00', app(AttendanceService::class)->latest($this->employee->id, '2026-11-04')->time_out);
        $this->post(route('la.corrections.decide', $request), ['outcome' => 'approved', 'reason' => 'Again'])->assertSessionHasErrors('decision');
        $this->actingAs($this->employee->user)->get(route('la.employee.history'))->assertOk()->assertSee('Approved')->assertSee('approved correction')->assertSee('Original captured punch events');
    }

    public function test_employee_pages_are_owned_and_admin_live_endpoint_is_denied(): void
    {
        foreach (['la.employee.today', 'la.employee.history'] as $route) {
            $this->get(route($route))->assertOk()->assertDontSee('HRLA Setup')->assertSee('Edit / Mark Attendance')->assertSee('Attendance History')->assertSee('My Account');
            $this->get(route($route, ['employee_id' => $this->hr->employee->id]))->assertForbidden();
        }
        $this->get(route('la.history', $this->hr->employee))->assertForbidden();
        $this->delete('/leave-attendance/attendance/punch/1')->assertNotFound();
        $this->actingAs($this->hr)->postJson(route('la.employee.punch'), $this->payload())->assertForbidden();
        $this->post(route('la.attendance.store'), $this->correction('2026-11-04') + ['employee_id' => $this->employee->id])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hrla_correction_requests', 0);
        $this->assertDatabaseHas('hrla_attendance_versions', ['source' => 'direct entry']);
    }

    public function test_attendance_day_uses_application_timezone_at_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 19:01:00', 'UTC'));
        $this->postJson(route('la.employee.punch'), $this->payload())->assertOk()->assertJsonPath('event.attendance_date', '2026-11-05')->assertJsonPath('event.recorded_at','2026-11-05 00:01:00');
    }
}
