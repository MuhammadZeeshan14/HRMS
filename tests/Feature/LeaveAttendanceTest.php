<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Models\LeaveAttendance\AttendanceVersion;
use App\Models\LeaveAttendance\CorrectionRequest;
use App\Models\LeaveAttendance\Holiday;
use App\Models\LeaveAttendance\ImportBatch;
use App\Models\LeaveAttendance\LeaveApplication;
use App\Models\LeaveAttendance\LeavePolicy;
use App\Models\LeaveAttendance\LeaveType;
use App\Models\LeaveAttendance\Shift;
use App\Models\LeaveAttendance\ShiftAssignment;
use App\Models\User;
use App\Policies\LeaveAttendancePolicy;
use App\Services\LeaveAttendance\AttendanceService;
use App\Services\LeaveAttendance\Calendar;
use App\Services\LeaveAttendance\ImportService;
use App\Services\LeaveAttendance\LeaveService;
use App\Services\LeaveAttendance\Register;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeaveAttendanceTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\EmployeeAccountFixtures;

    private User $hr;

    private User $worker;

    private User $manager;

    private User $secondary;

    private Employee $employee;

    private Employee $boss;

    private Employee $other;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(18, 0));
        $this->worker = User::factory()->create(['role' => 'employee', 'is_active' => true, 'must_change_password'=>false]);
        $this->manager = User::factory()->create(['role' => 'employee', 'is_active' => true, 'must_change_password'=>false]);
        $this->secondary = User::factory()->create(['role' => 'employee', 'is_active' => true, 'must_change_password'=>false]);
        $company = Company::create(['code' => 'TEST', 'name' => 'Isolated test company', 'status' => 'active']);
        $this->hr = $this->linkedHr($company);
        $root = \App\Models\Department::create(['code'=>'ROOT','name'=>'Root','company_id'=>$company->id,'status'=>'active']);
        $branch = \App\Models\Department::create(['code'=>'BRANCH','name'=>'Branch','company_id'=>$company->id,'parent_department_id'=>$root->id,'status'=>'active']);
        $otherBranch = \App\Models\Department::create(['code'=>'OTHER-BRANCH','name'=>'Other branch','company_id'=>$company->id,'status'=>'active']);
        $status = EmploymentStatus::create(['name' => 'Permanent', 'status' => 'active']);
        $make = fn ($code, $user = null, $department = null, $primary = null, $secondary = null) => Employee::create(['department_id'=>($department ?? $root)->id,'primary_report_to_id'=>$primary,'secondary_report_to_id'=>$secondary,'dual_reporting'=>(bool)$secondary,'employee_no' => $code, 'first_name' => 'Test', 'last_name' => $code, 'date_of_joining' => '2026-01-01', 'company_id' => $company->id, 'employment_type' => 'permanent', 'employment_status_id' => $status->id, 'employment_status' => 'active', 'user_id' => $user?->id]);
        $this->boss = $make('BOSS', $this->manager);
        $second = $make('SECOND', $this->secondary);
        $this->employee = $make('WORKER', $this->worker, $branch, $this->boss->id, $second->id);
        $this->other = $make('OTHER', null, $otherBranch);
        $shift = Shift::create(['name' => 'Test schedule', 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'overnight' => false,'company_id'=>$company->id]);
        ShiftAssignment::create(['employee_id' => $this->employee->id, 'shift_id' => $shift->id, 'starts_on' => '2026-01-01', 'actor_id' => $this->hr->id]);
        $this->type = LeaveType::create(['code' => 'TEST', 'name' => 'Test leave','company_id'=>$company->id]);
    }

    private function punches(array $extra = []): array
    {
        return $extra + ['employee_id' => $this->employee->id, 'shift_date' => '2026-10-29', 'base_version_id' => null, 'time_in' => '2026-10-29 09:16:07', 'time_out' => '2026-10-29 17:00:00', 'reason_type' => 'missed_punch', 'reason' => 'Isolated verification'];
    }

    public function test_bulk_shift_assignment_saves_all_selected_employees_and_actor(): void
    {
        $shift = Shift::firstOrFail();
        $this->actingAs($this->hr)->from('/leave-attendance/setup/assignments')->post(route('la.setup.assignments.bulk'), [
            'employee_ids_payload' => json_encode([$this->boss->id, $this->other->id]),
            'shift_id' => $shift->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30',
        ])->assertRedirect('/leave-attendance/setup/assignments')->assertSessionHasNoErrors()->assertSessionHas('shift_assignment_saved', true);
        foreach ([$this->boss, $this->other] as $employee) {
            $this->assertDatabaseHas('hrla_shift_assignments', ['employee_id' => $employee->id, 'shift_id' => $shift->id,
                'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'actor_id' => $this->hr->id]);
        }
        $this->assertDatabaseCount('hrla_shift_assignments', 3);
    }

    public function test_holiday_setup_uses_day_month_and_recurs_across_years(): void
    {
        $this->actingAs($this->hr)->post('/leave-attendance/setup/holidays', ['name' => 'Annual holiday', 'holiday_date' => '02/11'])->assertSessionHasNoErrors();
        $holiday = Holiday::firstOrFail();
        $this->assertSame('2000-11-02', $holiday->date);
        $this->assertSame(2, $holiday->day);
        $this->assertSame(11, $holiday->month);
        $calendar = app(Calendar::class);
        $this->assertTrue($calendar->off('2027-11-02'));
        $this->assertSame(0.0, $calendar->count('2027-11-02', '2027-11-02'));
        $this->get('/leave-attendance/setup/holidays')->assertOk()->assertSee('name="holiday_date"', false)->assertSee('02/11')->assertDontSee('name="day"', false)->assertDontSee('name="month"', false)->assertDontSee('type="date"', false);
        $this->put('/leave-attendance/setup/holidays/'.$holiday->id, ['name' => 'Moved holiday', 'holiday_date' => '03/11'])->assertSessionHasNoErrors();
        $this->assertFalse($calendar->off('2027-11-02'));
        $this->assertTrue($calendar->off('2027-11-03'));
    }

    public function test_holidays_reject_impossible_and_duplicate_month_days_and_handle_leap_years(): void
    {
        $this->actingAs($this->hr)->post('/leave-attendance/setup/holidays', ['name' => 'Invalid single field', 'holiday_date' => '31/04'])->assertSessionHasErrors('holiday_date');
        $this->post('/leave-attendance/setup/holidays', ['name' => 'Invalid format', 'holiday_date' => '2026-12-25'])->assertSessionHasErrors('holiday_date');
        $this->actingAs($this->hr)->post('/leave-attendance/setup/holidays', ['name' => 'Invalid', 'day' => 31, 'month' => 4])->assertSessionHasErrors('day');
        $this->post('/leave-attendance/setup/holidays', ['name' => 'Invalid', 'day' => 0, 'month' => 13])->assertSessionHasErrors(['day', 'month']);
        Holiday::create(['name' => 'Legacy holiday', 'date' => '2026-12-25']);
        $this->post('/leave-attendance/setup/holidays', ['name' => 'Duplicate legacy day', 'day' => 25, 'month' => 12])->assertSessionHasErrors('day');
        $this->post('/leave-attendance/setup/holidays', ['name' => 'Leap holiday', 'day' => 29, 'month' => 2])->assertSessionHasNoErrors();
        $this->post('/leave-attendance/setup/holidays', ['name' => 'Duplicate leap day', 'day' => 29, 'month' => 2])->assertSessionHasErrors('day');
        $calendar = app(Calendar::class);
        $this->assertTrue($calendar->off('2028-02-29'));
        $this->assertFalse($calendar->off('2027-03-01'));
        $this->assertSame(1.0, $calendar->count('2027-02-28', '2027-03-01'));
        $this->assertSame(2.0, $calendar->count('2028-02-28', '2028-03-01'));
        $this->post('/leave-attendance/setup/holidays', ['name' => 'New Year', 'day' => 1, 'month' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1.0, $calendar->count('2026-12-31', '2027-01-01'));
    }

    public function test_recurring_holiday_changes_protect_approved_leave_in_other_years(): void
    {
        LeaveApplication::create(['employee_id' => $this->employee->id, 'requester_id' => $this->worker->id, 'leave_type_id' => $this->type->id,
            'form_date' => '2028-01-01', 'starts_on' => '2028-11-02', 'ends_on' => '2028-11-02', 'chargeable_days' => 1, 'status' => 'approved', 'reason' => 'Approved leave protection fixture']);
        $this->actingAs($this->hr)->post('/leave-attendance/setup/holidays', ['name' => 'Annual holiday', 'day' => 2, 'month' => 11])->assertSessionHasErrors('date');
        $this->assertDatabaseCount('hrla_holidays', 0);
        $holiday = Holiday::create(['name' => 'Protected recurring holiday', 'date' => '2000-11-02']);
        $this->delete('/leave-attendance/setup/holidays/'.$holiday->id)->assertSessionHasErrors('date');
        $this->put('/leave-attendance/setup/holidays/'.$holiday->id, ['name' => 'Moved', 'day' => 3, 'month' => 11])->assertSessionHasErrors('date');
        $this->assertSame('2000-11-02', $holiday->fresh()->date);
        LeaveApplication::create(['employee_id' => $this->employee->id, 'requester_id' => $this->worker->id, 'leave_type_id' => $this->type->id,
            'form_date' => '2027-01-01', 'starts_on' => '2027-02-28', 'ends_on' => '2027-03-01', 'chargeable_days' => 1, 'status' => 'approved', 'reason' => 'Non-leap year fixture']);
        $this->post('/leave-attendance/setup/holidays', ['name' => 'Leap holiday', 'day' => 29, 'month' => 2])->assertSessionHasNoErrors();
    }

    public function test_bulk_assignment_edit_changes_selected_shifts_preserving_dates_and_attendance_snapshots(): void
    {
        $old = ShiftAssignment::firstOrFail();
        $bossAssignment = ShiftAssignment::create(['employee_id' => $this->boss->id, 'shift_id' => $old->shift_id, 'starts_on' => '2026-02-01', 'actor_id' => $this->hr->id]);
        $new = Shift::create(['name' => 'New shared schedule', 'start_time' => '10:00:00', 'end_time' => '18:00:00', 'overnight' => false]);
        $this->actingAs($this->hr)->post('/leave-attendance/attendance/entry', $this->punches(['direct' => 1]))->assertSessionHasNoErrors();
        $snapshot = AttendanceVersion::firstOrFail()->toArray();
        $this->from('/leave-attendance/setup/assignments?mode=edit')->put(route('la.setup.assignments.bulk-edit'), [
            'assignment_ids_payload' => json_encode([$old->id, $bossAssignment->id]), 'shift_id' => $new->id, 'end_action' => 'keep',
        ])->assertRedirect('/leave-attendance/setup/assignments?mode=edit')->assertSessionHasNoErrors()->assertSessionHas('shift_assignment_edit_saved', true);
        $this->assertSame($new->id, $old->fresh()->shift_id);
        $this->assertSame($new->id, $bossAssignment->fresh()->shift_id);
        $this->assertSame('2026-01-01', $old->fresh()->starts_on);
        $this->assertSame('2026-02-01', $bossAssignment->fresh()->starts_on);
        $this->assertNull($old->fresh()->ends_on);
        $this->assertDatabaseCount('hrla_shift_assignments', 2);
        $this->assertSame($snapshot, AttendanceVersion::firstOrFail()->toArray());
    }

    public function test_bulk_assignment_edit_sets_and_clears_end_dates(): void
    {
        $assignment = ShiftAssignment::firstOrFail();
        $this->actingAs($this->hr)->put(route('la.setup.assignments.bulk-edit'), [
            'assignment_ids' => [$assignment->id], 'end_action' => 'set', 'ends_on' => '2026-11-30',
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-30', $assignment->fresh()->ends_on);
        $this->put(route('la.setup.assignments.bulk-edit'), ['assignment_ids' => [$assignment->id], 'end_action' => 'clear'])->assertSessionHasNoErrors();
        $this->assertNull($assignment->fresh()->ends_on);
    }

    public function test_bulk_assignment_edit_rejects_overlaps_with_unselected_and_selected_records_atomically(): void
    {
        $assignment = ShiftAssignment::firstOrFail();
        $assignment->update(['ends_on' => '2026-06-30']);
        $later = ShiftAssignment::create(['employee_id' => $this->employee->id, 'shift_id' => $assignment->shift_id, 'starts_on' => '2026-07-01', 'actor_id' => $this->hr->id]);
        $this->actingAs($this->hr)->put(route('la.setup.assignments.bulk-edit'), [
            'assignment_ids' => [$assignment->id], 'end_action' => 'clear',
        ])->assertSessionHasErrors('starts_on');
        $this->assertSame('2026-06-30', $assignment->fresh()->ends_on);
        $this->put(route('la.setup.assignments.bulk-edit'), [
            'assignment_ids' => [$assignment->id, $later->id], 'starts_on' => '2026-01-01', 'end_action' => 'keep',
        ])->assertSessionHasErrors('starts_on');
        $this->assertSame('2026-07-01', $later->fresh()->starts_on);
        $this->put(route('la.setup.assignments.bulk-edit'), [
            'assignment_ids' => [$assignment->id, $later->id], 'ends_on' => '2026-03-31', 'end_action' => 'set',
        ])->assertSessionHasErrors('ends_on');
        $this->assertSame('2026-06-30', $assignment->fresh()->ends_on);
        $this->assertNull($later->fresh()->ends_on);
    }

    public function test_bulk_assignment_edit_requires_valid_selection_changes_and_hr_authority(): void
    {
        $id = ShiftAssignment::value('id');
        $url = route('la.setup.assignments.bulk-edit');
        $this->actingAs($this->worker)->put($url, ['assignment_ids' => [$id], 'end_action' => 'clear'])->assertForbidden();
        $this->actingAs($this->hr)->put($url, ['assignment_ids' => [$id], 'end_action' => 'keep'])->assertSessionHasErrors('changes');
        $this->put($url, ['assignment_ids_payload' => 'invalid', 'end_action' => 'set'])->assertSessionHasErrors(['assignment_ids', 'ends_on']);
        $this->put($url, ['assignment_ids' => [$id, $id], 'end_action' => 'clear'])->assertSessionHasErrors('assignment_ids.0');
        $this->put($url, ['assignment_ids' => [999999], 'shift_id' => 999999, 'end_action' => 'clear'])->assertSessionHasErrors(['assignment_ids', 'shift_id']);
        $this->assertDatabaseCount('hrla_shift_assignments', 1);
        $this->assertNull(ShiftAssignment::firstOrFail()->ends_on);
    }

    public function test_assignment_screen_has_one_employee_table_and_one_set_shift_modal(): void
    {
        $assignment = ShiftAssignment::firstOrFail();
        ShiftAssignment::create(['employee_id' => $this->boss->id, 'shift_id' => $assignment->shift_id, 'starts_on' => '2027-01-01', 'actor_id' => $this->hr->id]);
        ShiftAssignment::create(['employee_id' => $this->other->id, 'shift_id' => $assignment->shift_id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-02-01', 'actor_id' => $this->hr->id]);
        $response = $this->actingAs($this->hr)->get('/leave-attendance/setup/assignments')->assertOk()
            ->assertSee('data-shift-dialog="set"', false)->assertDontSee('data-shift-dialog="edit"', false);
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        $this->assertSame(1, $xpath->query('//table')->length);
        $this->assertSame(1, $xpath->query('//dialog/form')->length);
        $this->get('/leave-attendance/setup/assignments?assignment_on=2027-01-02')->assertOk()
            ->assertViewHas('currentAssignments', fn ($assignments) => $assignments->has($this->boss->id));
        $this->get('/leave-attendance/setup/assignments?assignment_on=2026-01-02')->assertOk()
            ->assertViewHas('currentAssignments', fn ($assignments) => $assignments->has($this->other->id));
        $this->get('/leave-attendance/setup/assignments?assignment_on=invalid')->assertSessionHasErrors('assignment_on');
    }

    public function test_bulk_shift_assignment_overrides_existing_assignments_and_keeps_history(): void
    {
        $previous = ShiftAssignment::firstOrFail();
        $this->actingAs($this->hr)->post(route('la.setup.assignments.bulk'), [
            'employee_ids' => [$this->boss->id, $this->employee->id], 'shift_id' => Shift::value('id'), 'starts_on' => '2026-11-01',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hrla_shift_assignments', 3);
        $this->assertSame('2026-01-01', $previous->fresh()->starts_on);
        $this->assertNull($previous->fresh()->ends_on);
        $this->assertNotSame($previous->id, ShiftAssignment::where('employee_id', $this->employee->id)->effectiveOn('2026-11-02')->firstOrFail()->id);
    }

    public function test_newest_assignment_wins_over_later_start_dates_in_setup_filters_and_register(): void
    {
        $a = Shift::create(['name' => 'Earlier saved future shift', 'start_time' => '08:00:00', 'end_time' => '16:00:00', 'overnight' => false]);
        $b = Shift::create(['name' => 'New backdated shift', 'start_time' => '10:00:00', 'end_time' => '18:00:00', 'overnight' => false]);
        ShiftAssignment::create(['employee_id' => $this->employee->id, 'shift_id' => $a->id, 'starts_on' => '2026-11-10', 'actor_id' => $this->hr->id]);
        $this->actingAs($this->hr)->post(route('la.setup.assignments.bulk'), [
            'employee_ids' => [$this->employee->id], 'shift_id' => $b->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-20',
        ])->assertSessionHasNoErrors();
        $service = app(AttendanceService::class);
        $this->assertSame($b->id, $service->schedule($this->employee->id, '2026-11-15')['shift_id']);
        $this->assertSame($a->id, $service->schedule($this->employee->id, '2026-11-21')['shift_id']);
        $this->get('/leave-attendance/setup/assignments?assignment_on=2026-11-15&current_shift='.$b->id)->assertOk()
            ->assertViewHas('currentAssignments', fn ($assignments) => $assignments->get($this->employee->id)?->shift_id === $b->id);
        $this->get('/leave-attendance/setup/assignments?assignment_on=2026-11-15&current_shift='.$a->id)->assertOk()
            ->assertViewHas('assignmentEmployees', fn ($employees) => !$employees->contains('id', $this->employee->id));
        $register = app(Register::class);
        $rows = collect($register->rows(collect([$this->employee]), '2026-11-20', '2026-11-21'))->keyBy('date');
        $this->assertSame($b->name, $rows['2026-11-20']['schedule']['shift_name']);
        $this->assertSame($a->name, $rows['2026-11-21']['schedule']['shift_name']);
        $this->assertSame($b->name, $register->row($this->employee, '2026-11-20')['schedule']['shift_name']);
        // A third assignment with exactly the same date range is the new winner.
        $this->post(route('la.setup.assignments.bulk'), ['employee_ids' => [$this->employee->id], 'shift_id' => $a->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-20'])->assertSessionHasNoErrors();
        $this->assertSame($a->id, $service->schedule($this->employee->id, '2026-11-15')['shift_id']);
        $this->assertDatabaseCount('hrla_shift_assignments', 4);
    }

    public function test_setting_new_shift_retains_committed_attendance_snapshot(): void
    {
        $this->actingAs($this->hr)->post('/leave-attendance/attendance/entry?direct=1', $this->punches())->assertSessionHasNoErrors();
        $version = AttendanceVersion::firstOrFail();
        $before = $version->getAttributes();
        $shift = Shift::create(['name' => 'New overnight override', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'overnight' => true]);
        $this->post(route('la.setup.assignments.bulk'), ['employee_ids' => [$this->employee->id], 'shift_id' => $shift->id, 'starts_on' => '2026-10-01'])->assertSessionHasNoErrors();
        $this->assertSame($before, $version->fresh()->getAttributes());
        $this->assertSame($shift->id, app(AttendanceService::class)->schedule($this->employee->id, '2026-10-29')['shift_id']);
        $this->assertSame($version->shift_name, app(Register::class)->row($this->employee, '2026-10-29')['schedule']['shift_name']);
        $this->assertSame($version->shift_name, app(Register::class)->rows(collect([$this->employee]), '2026-10-29', '2026-10-29')[0]['schedule']['shift_name']);
    }

    public function test_bulk_shift_assignment_allows_adjacent_dates_without_rewriting_history(): void
    {
        $previous = ShiftAssignment::firstOrFail();
        $previous->update(['ends_on' => '2026-10-31']);
        $this->actingAs($this->hr)->post(route('la.setup.assignments.bulk'), [
            'employee_ids' => [$this->employee->id], 'shift_id' => Shift::value('id'), 'starts_on' => '2026-11-01',
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-31', $previous->fresh()->ends_on);
        $this->assertDatabaseHas('hrla_shift_assignments', ['employee_id' => $this->employee->id, 'starts_on' => '2026-11-01', 'ends_on' => null]);
    }

    public function test_bulk_shift_assignment_validates_selection_shift_and_dates(): void
    {
        $this->actingAs($this->hr);
        foreach ([[], [$this->boss->id, $this->boss->id], [999999]] as $ids) {
            $response = $this->post(route('la.setup.assignments.bulk'), ['employee_ids' => $ids, 'shift_id' => Shift::value('id'), 'starts_on' => '2026-11-01']);
            $this->assertTrue(session('errors')->hasAny(['employee_ids', 'employee_ids.0', 'employee_ids.1']));
            $response->assertRedirect();
        }
        $this->post(route('la.setup.assignments.bulk'), ['employee_ids_payload' => '{invalid', 'shift_id' => 999999,
            'starts_on' => '2026-11-02', 'ends_on' => '2026-11-01'])->assertSessionHasErrors(['employee_ids', 'shift_id', 'ends_on']);
        $this->assertDatabaseCount('hrla_shift_assignments', 1);
    }

    public function test_bulk_shift_assignment_is_restricted_to_active_hr_and_admin(): void
    {
        $data = ['employee_ids' => [$this->other->id], 'shift_id' => Shift::value('id'), 'starts_on' => '2026-11-01'];
        $this->actingAs($this->manager)->post(route('la.setup.assignments.bulk'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hrla_shift_assignments', ['employee_id' => $this->other->id, 'actor_id' => $this->manager->id]);
        $this->actingAs($this->worker)->post(route('la.setup.assignments.bulk'), $data)->assertForbidden();
        $this->hr->update(['is_active' => false]);
        $this->actingAs($this->hr)->post(route('la.setup.assignments.bulk'), $data)->assertRedirect('/login');
        $this->assertDatabaseCount('hrla_shift_assignments', 2);
    }

    public function test_assignment_employee_table_search_and_current_shift_filters(): void
    {
        $this->actingAs($this->hr)->get('/leave-attendance/setup/assignments?employee_q=OTHER')->assertOk()
            ->assertViewHas('assignmentEmployees', fn ($employees) => $employees->total() === 1 && $employees->first()->id === $this->other->id)
            ->assertSee('Employee shift assignments')->assertSee('data-select-employee', false)->assertSee('Set Shift')->assertDontSee('Edit Shift');
        $this->get('/leave-attendance/setup/assignments?current_shift=unassigned')->assertOk()
            ->assertViewHas('assignmentEmployees', fn ($employees) => ! $employees->contains('id', $this->employee->id));
        $this->get('/leave-attendance/setup/assignments?current_shift='.Shift::value('id'))->assertOk()
            ->assertViewHas('assignmentEmployees', fn ($employees) => $employees->total() === 1 && $employees->first()->id === $this->employee->id);
        $this->get('/leave-attendance/setup/assignments?status=inactive')->assertOk()
            ->assertViewHas('assignmentEmployees', fn ($employees) => $employees->total() === 0);
        $this->get('/leave-attendance/setup/assignments?company_id=999999')->assertSessionHasErrors('company_id');
    }

    public function test_segmented_time_fields_save_validated_hours_minutes_and_meridiem(): void
    {
        $this->actingAs($this->hr)->post('/leave-attendance/setup/shifts', [
            'name' => 'Segmented clock fixture', 'start_time_hour' => '10', 'start_time_minute' => '00', 'start_time_period' => 'PM',
            'end_time_hour' => '06', 'end_time_minute' => '05', 'end_time_period' => 'AM', 'overnight' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hrla_shifts', ['name' => 'Segmented clock fixture', 'start_time' => '22:00:00', 'end_time' => '06:05:00']);
        $this->get('/leave-attendance/setup/shifts')->assertOk()->assertSee('name="start_time_hour"', false)->assertSee('name="start_time_minute"', false)->assertSee('name="start_time_period"', false);
        $this->post('/leave-attendance/setup/shifts', [
            'name' => 'Invalid segmented clock', 'start_time_hour' => '13', 'start_time_minute' => '00', 'start_time_period' => 'PM',
            'end_time_hour' => '06', 'end_time_minute' => '00', 'end_time_period' => 'AM', 'overnight' => 1,
        ])->assertSessionHasErrors('start_time');
        $this->post('/leave-attendance/attendance/entry', $this->punches([
            'direct' => 1, 'time_in_date' => '2026-10-29', 'time_in_clock_hour' => '09', 'time_in_clock_minute' => '16', 'time_in_clock_period' => 'AM',
            'time_out_date' => '2026-10-29', 'time_out_clock_hour' => '05', 'time_out_clock_minute' => '00', 'time_out_clock_period' => 'PM',
        ]))->assertSessionHasNoErrors();
        $latest = app(AttendanceService::class)->latest($this->employee->id, '2026-10-29');
        $this->assertSame('2026-10-29 09:16:07', $latest->time_in);
        $this->assertSame('2026-10-29 17:00:00', $latest->time_out);
    }

    public function test_shift_setup_accepts_am_pm_and_renders_twelve_hour_inputs_and_outputs(): void
    {
        $shift = Shift::firstOrFail();
        $this->actingAs($this->hr)->put('/leave-attendance/setup/shifts/'.$shift->id, ['name' => 'Noon boundary fixture', 'start_time' => '12:00 AM', 'end_time' => '12:00 PM', 'overnight' => 0])->assertSessionHasNoErrors();
        $this->assertSame('00:00:00', $shift->fresh()->start_time);
        $this->assertSame('12:00:00', $shift->fresh()->end_time);
        $this->get('/leave-attendance/setup/shifts')->assertOk()->assertSee('12:00 AM')->assertSee('12:00 PM')->assertDontSee('type="time"', false);
        $this->put('/leave-attendance/setup/shifts/'.$shift->id, ['name' => 'Invalid clock', 'start_time' => '13:00 PM', 'end_time' => '6:00 PM', 'overnight' => 0])->assertSessionHasErrors('start_time');
    }

    public function test_am_pm_punch_fields_keep_overnight_dates_and_original_seconds(): void
    {
        Shift::firstOrFail()->update(['start_time' => '22:00:00', 'end_time' => '06:00:00', 'overnight' => true]);
        $version = app(AttendanceService::class)->append($this->employee, $this->hr, $this->punches(['time_in' => '2026-10-29 22:00:37', 'time_out' => '2026-10-30 06:05:47']), 'direct entry', null);
        $this->actingAs($this->hr)->get('/leave-attendance/attendance/entry?direct=1&employee_id='.$this->employee->id.'&shift_date=2026-10-29')->assertOk()->assertSee('10:00 PM')->assertSee('6:05 AM')->assertSee('name="time_out_date" value="2026-10-30"', false)->assertDontSee('type="datetime-local"', false);
        $this->post('/leave-attendance/attendance/entry', $this->punches(['direct' => 1, 'base_version_id' => $version->id, 'time_in' => $version->time_in, 'time_out' => $version->time_out, 'time_in_date' => '2026-10-29', 'time_in_clock' => '10:00 PM', 'time_out_date' => '2026-10-30', 'time_out_clock' => '6:05 AM']))->assertSessionHasNoErrors();
        $latest = app(AttendanceService::class)->latest($this->employee->id, '2026-10-29');
        $this->assertSame('2026-10-29 22:00:37', $latest->time_in);
        $this->assertSame('2026-10-30 06:05:47', $latest->time_out);
        $this->assertSame(29110, app(Register::class)->row($this->employee, '2026-10-29')['work_seconds']);
        $this->get('/leave-attendance/attendance/history/'.$this->employee->id)->assertOk()->assertSee('2026-10-30 6:05 AM');
    }

    public function test_import_accepts_twelve_hour_timestamps_with_explicit_dates(): void
    {
        Shift::firstOrFail()->update(['start_time' => '22:00:00', 'end_time' => '06:00:00', 'overnight' => true]);
        $batch = ImportBatch::create(['fingerprint' => str_repeat('e', 64), 'filename' => 'am-pm-fixture.xlsx', 'actor_id' => $this->hr->id, 'headers' => ['Code', 'Date', 'Time In', 'Time Out'], 'rows' => [['row' => 2, 'cells' => ['WORKER', '2026-10-29', '2026-10-29 10:00 PM', '2026-10-30 6:05 AM']]]]);
        $results = app(ImportService::class)->preview($batch, ['employee_no' => 0, 'shift_date' => 1, 'time_in' => 2, 'time_out' => 3], $this->hr);
        $this->assertSame([], $results[0]['errors']);
        $this->assertSame('2026-10-29 22:00:00', $results[0]['data']['time_in']);
        $this->assertSame('2026-10-30 06:05:00', $results[0]['data']['time_out']);
    }

    public function test_hrla_dashboard_scopes_activity_and_separates_module_navigation(): void
    {
        $this->actingAs($this->hr)->post('/leave-attendance/leave/apply', $this->leave())->assertSessionHasNoErrors();
        $this->actingAs($this->worker)->get('/leave-attendance/dashboard')->assertOk()
            ->assertViewHas('metrics', fn ($metrics) => $metrics['employees'] === 1 && $metrics['pending_leaves'] === 1)
            ->assertSee('HRLA Dashboard')->assertDontSee('Company Departments')->assertDontSee('HRLA Setup');
        $this->actingAs($this->manager)->get('/leave-attendance/dashboard')->assertOk()
            ->assertViewHas('metrics', fn ($metrics) => $metrics['employees'] === Employee::where('company_id', $this->employee->company_id)->count() && $metrics['pending_leaves'] === 1);
        $unlinked = User::factory()->create(['role' => 'employee', 'is_active' => true, 'must_change_password'=>false]);
        $this->actingAs($unlinked)->get('/leave-attendance/dashboard')->assertRedirect('/login');
        $this->actingAs($this->hr)->get('/')->assertOk()->assertSee('System modules')->assertSee('HRIS Setup')->assertDontSee('HRLA Dashboard');
    }

    public function test_hrla_setup_landing_and_dashboard_require_authorized_active_users(): void
    {
        $this->get('/leave-attendance/dashboard')->assertRedirect('/login');
        $this->get('/leave-attendance/setup')->assertRedirect('/login');
        $this->actingAs($this->hr)->get('/leave-attendance/setup')->assertOk()
            ->assertSee('HRLA Setup')->assertSee('Shift assignments')->assertSee('Leave policies');
        $this->actingAs($this->worker)->get('/leave-attendance/setup')->assertForbidden();
        $this->worker->update(['is_active' => false]);
        $this->get('/leave-attendance/dashboard')->assertRedirect('/login');
    }

    public function test_setup_searches_records_and_related_employee_and_leave_type_names(): void
    {
        $this->actingAs($this->hr);
        $this->get('/leave-attendance/setup/shifts?q=Test')->assertOk()
            ->assertViewHas('records', fn ($records) => $records->total() === 1);
        $this->get('/leave-attendance/setup/shifts?q=missing')->assertOk()
            ->assertViewHas('records', fn ($records) => $records->total() === 0);
        $this->get('/leave-attendance/setup/assignments?q=WORKER')->assertOk()
            ->assertViewHas('records', fn ($records) => $records->total() === 1);
        $this->policy();
        $this->get('/leave-attendance/setup/policies?q=Test+leave')->assertOk()
            ->assertViewHas('records', fn ($records) => $records->total() === 1);
        $this->get('/leave-attendance/setup/types?q=TEST')->assertOk()
            ->assertViewHas('records', fn ($records) => $records->total() === 1);
    }

    public function test_setup_edit_dialog_preserves_invalid_input_and_saves_with_existing_rules(): void
    {
        $shift = Shift::firstOrFail();
        $url = '/leave-attendance/setup/shifts';
        $this->actingAs($this->hr)->from($url)->put($url.'/'.$shift->id, [
            '_edit_record_id' => $shift->id, 'name' => 'Attempted edit', 'start_time' => '18:00', 'end_time' => '09:00', 'overnight' => 0,
        ])->assertRedirect($url)->assertSessionHasErrors('end_time');
        $page = $this->get($url)->assertOk();
        $html = new \DOMDocument;
        @$html->loadHTML($page->getContent());
        $xpath = new \DOMXPath($html);
        $this->assertSame(1, $xpath->query('//dialog[@data-reopen-edit]')->length);
        $this->assertSame('Attempted edit', $xpath->evaluate('string(//dialog//input[@name="name"]/@value)'));
        $this->assertSame('', $xpath->evaluate('string(//form[@action="'.url($url).'" and not(ancestor::dialog)]//input[@name="name"]/@value)'));
        $this->assertSame('Test schedule', $shift->fresh()->name);
        $this->put($url.'/'.$shift->id, [
            '_edit_record_id' => $shift->id, 'name' => 'Updated schedule', 'start_time' => '09:00', 'end_time' => '18:00', 'overnight' => 0,
        ])->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertSame('Updated schedule', $shift->fresh()->name);
    }

    private function leave(array $extra = []): array
    {
        return $extra + ['employee_id' => $this->employee->id, 'form_date' => '2026-10-29', 'leave_type_id' => $this->type->id, 'starts_on' => '2026-10-30', 'ends_on' => '2026-11-02', 'reason' => 'Test application'];
    }

    private function policy(array $extra = []): LeavePolicy
    {
        return LeavePolicy::create($extra + ['employment_status_id' => $this->employee->employment_status_id, 'employment_type' => 'permanent', 'leave_type_id' => $this->type->id, 'allowance_kind' => 'numeric', 'allowance' => 5]);
    }

    public function test_policy_setup_uses_shared_hris_statuses_even_without_employees_in_that_status(): void
    {
        $probation = EmploymentStatus::create(['name' => 'Probation', 'status' => 'active']);
        $data = ['employment_status_id' => $probation->id, 'leave_type_id' => $this->type->id, 'allowance_kind' => 'numeric', 'allowance' => 3];
        $this->actingAs($this->hr)->get('/leave-attendance/setup/policies')->assertOk()->assertSee('Employee status')->assertSee('Probation')->assertSee('name="employment_status_id"', false);
        $this->post('/leave-attendance/setup/policies', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hrla_leave_policies', ['employment_status_id' => $probation->id, 'employment_type' => 'probation', 'leave_type_id' => $this->type->id]);
        $this->post('/leave-attendance/setup/policies', $data)->assertSessionHasErrors('leave_type_id');
        $this->get('/leave-attendance/setup/policies?q=Probation')->assertViewHas('records', fn ($records) => $records->total() === 1);
    }

    public function test_policy_overview_lists_every_type_and_shows_employee_specific_approved_usage(): void
    {
        $this->policy();
        $unconfigured = LeaveType::create(['code' => 'UNCONFIG', 'name' => 'Unconfigured leave']);
        $this->actingAs($this->worker)->post('/leave-attendance/leave/apply', $this->leave(['starts_on' => '2026-10-29', 'ends_on' => '2026-10-30']))->assertSessionHasNoErrors();
        $leave = LeaveApplication::firstOrFail();
        $this->actingAs($this->hr)->post('/leave-attendance/leave/'.$leave->id.'/decision', ['outcome' => 'approved', 'reason' => 'Approved fixture'])->assertSessionHasNoErrors();
        $this->actingAs($this->worker)->post('/leave-attendance/leave/apply', $this->leave(['starts_on' => '2026-11-04', 'ends_on' => '2026-11-04']))->assertSessionHasNoErrors();
        $url = '/leave-attendance/setup/policies?employment_status_id='.$this->employee->employment_status_id;
        $this->actingAs($this->hr)->get($url.'&employee_id='.$this->employee->id)->assertOk()
            ->assertViewHas('policyRows', function ($rows) use ($unconfigured) {
                $configured = $rows->firstWhere('type.id', $this->type->id);
                $missing = $rows->firstWhere('type.id', $unconfigured->id);

                return $rows->count() === 2 && $configured['used'] === 2.0 && $configured['remaining'] === 3.0 && $missing['policy'] === null && $missing['remaining'] === null;
            })->assertSee('Total leaves assigned')->assertSee('Leave used')->assertSee('Leaves left')->assertSee('Unconfigured leave');
        $this->get($url.'&employee_id='.$this->boss->id)->assertOk()->assertViewHas('policyRows', fn ($rows) => $rows->firstWhere('type.id', $this->type->id)['used'] === 0.0);
    }

    public function test_policy_overview_validates_status_employee_pair_and_supports_statuses_without_employees(): void
    {
        $probation = EmploymentStatus::create(['name' => 'Probation', 'status' => 'active']);
        $this->policy(['employment_status_id' => $probation->id, 'employment_type' => 'probation']);
        $url = '/leave-attendance/setup/policies?employment_status_id='.$probation->id;
        $this->actingAs($this->hr)->get($url)->assertOk()->assertViewHas('policyRows', fn ($rows) => $rows->count() === 1 && $rows->first()['used'] === null && $rows->first()['remaining'] === null)
            ->assertSee('No employees currently have this status');
        $this->get($url.'&employee_id='.$this->employee->id)->assertSessionHasErrors('employee_id');
        $this->get('/leave-attendance/setup/policies?employment_status_id=999999')->assertSessionHasErrors('employment_status_id');
        $this->actingAs($this->worker)->get($url)->assertForbidden();
    }

    public function test_configuring_a_type_from_policy_overview_preserves_selected_status_and_employee(): void
    {
        $statusId = $this->employee->employment_status_id;
        $this->actingAs($this->hr)->post('/leave-attendance/setup/policies', [
            'employment_status_id' => $statusId, 'leave_type_id' => $this->type->id, 'allowance_kind' => 'numeric', 'allowance' => 5,
            '_balance_employee_id' => $this->employee->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('la.setup', ['kind' => 'policies', 'employment_status_id' => $statusId, 'employee_id' => $this->employee->id]));
        $this->get('/leave-attendance/setup/policies?employment_status_id='.$statusId)->assertOk()->assertSee('policy-type-'.$this->type->id)->assertSee('5.00');
    }

    public function test_policy_matching_uses_status_identity_after_renames_and_ignores_record_lifecycle(): void
    {
        $policy = $this->policy();
        $status = $this->employee->employeeStatus;
        $status->update(['name' => 'Confirmed']);
        $this->employee->update(['employment_type' => 'contract', 'employment_status' => 'on_leave']);
        $this->assertSame($policy->id, app(LeaveService::class)->policy($this->employee->fresh(), $this->type->id)->id);
        $this->actingAs($this->hr)->get('/leave-attendance/balances?employee_id='.$this->employee->id)->assertOk()->assertSee('Confirmed');
        $this->employee->update(['employment_status_id' => null]);
        $this->assertNull(app(LeaveService::class)->policy($this->employee->fresh(), $this->type->id));
    }

    public function test_employee_status_changed_in_hris_is_rechecked_when_approving_leave(): void
    {
        $this->policy();
        $probation = EmploymentStatus::create(['name' => 'Probation', 'status' => 'active']);
        $this->policy(['employment_status_id' => $probation->id, 'employment_type' => 'probation', 'allowance' => 0]);
        $this->actingAs($this->worker)->post('/leave-attendance/leave/apply', $this->leave())->assertSessionHasNoErrors();
        $leave = LeaveApplication::firstOrFail();
        $this->actingAs($this->hr)->put(route('employees.update', $this->employee), [
            'employee_no' => $this->employee->employee_no, 'full_name' => 'Test WORKER', 'date_of_joining' => '2026-01-01',
            'company_id' => $this->employee->company_id, 'employment_status' => 'active', 'employment_status_id' => $probation->id, '_status_source' => 'hris',
        ])->assertSessionHasNoErrors();
        $this->assertSame($probation->id, $this->employee->fresh()->employment_status_id);
        $this->assertSame('probation', $this->employee->fresh()->employment_type);
        $this->post('/leave-attendance/leave/'.$leave->id.'/decision', ['outcome' => 'approved', 'reason' => 'Review status'])->assertSessionHasErrors('decision');
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_status_link_migration_backfills_matches_and_preserves_legacy_data_and_snapshots(): void
    {
        $policy = $this->policy();
        $legacy = Employee::create(['department_id'=>$this->boss->department_id,'employee_no' => 'UNMATCHED', 'first_name' => 'Unmatched', 'last_name' => 'Fixture', 'company_id' => $this->employee->company_id,
            'date_of_joining' => '2026-01-01', 'employment_type' => 'temporary', 'employment_status' => 'active']);
        $leave = LeaveApplication::create(['employee_id' => $this->employee->id, 'requester_id' => $this->worker->id, 'leave_type_id' => $this->type->id,
            'form_date' => '2026-01-01', 'starts_on' => '2026-10-30', 'ends_on' => '2026-10-30', 'chargeable_days' => 1, 'status' => 'approved',
            'reason' => 'Migration fixture', 'policy_id' => $policy->id, 'policy_snapshot' => ['employment_type' => 'permanent', 'allowance' => 5]]);
        $snapshot = $leave->policy_snapshot;
        $migration = require database_path('migrations/2026_11_02_001000_link_hrla_employee_statuses.php');
        $migration->down();
        $migration->up();
        $this->assertSame($policy->employment_status_id, $this->employee->fresh()->employment_status_id);
        $this->assertSame($policy->employment_status_id, $policy->fresh()->employment_status_id);
        $this->assertNull($legacy->fresh()->employment_status_id);
        $this->assertSame('temporary', $legacy->fresh()->employment_type);
        $this->assertSame($snapshot, $leave->fresh()->policy_snapshot);
    }

    public function test_every_module_screen_renders_with_existing_layout_and_authorized_options(): void
    {
        $this->actingAs($this->hr);
        foreach (['/leave-attendance', '/leave-attendance/attendance/entry?direct=1', '/leave-attendance/corrections', '/leave-attendance/leave/apply', '/leave-attendance/leave', '/leave-attendance/balances', '/leave-attendance/import', '/leave-attendance/setup/shifts', '/leave-attendance/setup/holidays', '/leave-attendance/setup/types', '/leave-attendance/setup/policies', '/leave-attendance/setup/assignments', '/leave-attendance/attendance/history/'.$this->employee->id] as $url) {
            $this->get($url)->assertOk()->assertSee('ahamson-logo.png');
        }
        $this->actingAs($this->worker)->get('/leave-attendance/attendance/entry')->assertOk()->assertSee('WORKER')->assertDontSee('name="outcome"', false);
        $this->get('/leave-attendance/leave/apply')->assertOk()->assertDontSee('Test OTHER');
    }

    public function test_setup_permissions_validation_and_no_sample_configuration(): void
    {
        $this->actingAs($this->worker)->get('/leave-attendance/setup')->assertForbidden();
        $this->post('/leave-attendance/setup/holidays', ['name' => 'Bad', 'date' => '2026-01-01'])->assertForbidden();
        $this->actingAs($this->hr)->post('/leave-attendance/setup/shifts', ['name' => 'Actual', 'start_time' => '09:00', 'end_time' => '06:00', 'overnight' => 0])->assertSessionHasErrors('end_time');
        $this->post('/leave-attendance/setup/shifts', ['name' => 'Actual', 'start_time' => '22:00', 'end_time' => '06:00', 'overnight' => 1])->assertSessionHasNoErrors();
        $this->assertSame(0, Holiday::count());
        $this->assertSame(0, LeavePolicy::count());
        $this->post('/leave-attendance/setup/policies', ['employment_status_id' => 999999, 'leave_type_id' => $this->type->id, 'allowance_kind' => 'numeric', 'allowance' => 5])->assertSessionHasErrors('employment_status_id');
    }

    public function test_employee_identity_tampering_pending_effect_and_exactly_once_approval(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(18, 0));
        $this->actingAs($this->worker)->post('/leave-attendance/attendance/entry', $this->punches(['employee_id' => $this->other->id, 'actor_id' => $this->hr->id, 'status' => 'approved']))->assertSessionHasNoErrors()->assertRedirect('/leave-attendance/corrections');
        $c = CorrectionRequest::firstOrFail();
        $this->assertSame($this->employee->id, $c->employee_id);
        $this->assertSame('pending', $c->status);
        $this->assertSame(0, AttendanceVersion::count());
        $this->post('/leave-attendance/corrections/'.$c->id.'/decision', ['outcome' => 'approved', 'reason' => 'Self'])->assertForbidden();
        $this->actingAs($this->secondary)->post('/leave-attendance/corrections/'.$c->id.'/decision', ['outcome' => 'approved', 'reason' => 'Reviewed'])->assertSessionHasNoErrors();
        $this->assertSame(1, AttendanceVersion::count());
        $this->assertDatabaseHas('hrla_attendance_versions', ['actor_id' => $this->secondary->id, 'time_in' => '2026-10-29 09:16:07']);
        $this->actingAs($this->hr)->post('/leave-attendance/corrections/'.$c->id.'/decision', ['outcome' => 'approved', 'reason' => 'Again'])->assertSessionHasErrors('decision');
        $this->assertSame(1, AttendanceVersion::count());
    }

    public function test_direct_entry_scope_version_preservation_and_stale_request_detection(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(18, 0));
        $this->actingAs($this->worker)->post('/leave-attendance/attendance/entry', $this->punches());
        $c = CorrectionRequest::firstOrFail();
        $this->actingAs($this->manager)->post('/leave-attendance/attendance/entry', $this->punches(['direct' => 1]))->assertSessionHasNoErrors();
        $first = AttendanceVersion::firstOrFail();
        $this->actingAs($this->hr)->post('/leave-attendance/corrections/'.$c->id.'/decision', ['outcome' => 'approved', 'reason' => 'Stale'])->assertSessionHasErrors('base_version_id');
        $this->assertSame('pending', $c->fresh()->status);
        $this->post('/leave-attendance/attendance/entry', $this->punches(['direct' => 1, 'base_version_id' => $first->id, 'time_in' => '2026-10-29 09:00:00']))->assertSessionHasNoErrors();
        $this->assertSame(2, AttendanceVersion::count());
        $this->assertSame($first->time_in, $first->fresh()->time_in);
        $latest = app(AttendanceService::class)->latest($this->employee->id, '2026-10-29');
        $this->assertGreaterThan($first->id, $latest->id);
        ShiftAssignment::create(['employee_id' => $this->other->id, 'shift_id' => Shift::first()->id, 'starts_on' => '2026-01-01', 'actor_id' => $this->hr->id]);
        $this->actingAs($this->manager)->post('/leave-attendance/attendance/entry', $this->punches(['direct' => 1, 'employee_id' => $this->other->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hrla_attendance_versions', ['employee_id' => $this->other->id, 'actor_id' => $this->manager->id]);
    }

    public function test_rejection_requires_reason_and_does_not_commit(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(18, 0));
        $this->actingAs($this->worker)->post('/leave-attendance/attendance/entry', $this->punches());
        $c = CorrectionRequest::firstOrFail();
        $this->actingAs($this->hr)->post('/leave-attendance/corrections/'.$c->id.'/decision', ['outcome' => 'rejected'])->assertSessionHasErrors('reason');
        $this->post('/leave-attendance/corrections/'.$c->id.'/decision', ['outcome' => 'rejected', 'reason' => 'Incorrect date'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $c->fresh()->status);
        $this->assertSame(0, AttendanceVersion::count());
    }

    public function test_client_reason_requires_name_and_incomplete_punches_remain_empty(): void
    {
        $this->actingAs($this->worker)->post('/leave-attendance/attendance/entry', $this->punches(['reason_type' => 'client_meeting']))->assertSessionHasErrors('client_name');
        $this->actingAs($this->hr)->post('/leave-attendance/attendance/entry', $this->punches(['direct' => 1, 'time_out' => null]))->assertSessionHasNoErrors();
        $row = app(Register::class)->row($this->employee, '2026-10-29');
        $this->assertSame('Missing Time Out', $row['status']);
        $this->assertNull($row['diff_seconds']);
    }

    public function test_calendar_days_weekend_holiday_double_exclusion_and_half_units(): void
    {
        $calendar = app(Calendar::class);
        $this->assertSame(2.0, $calendar->count('2026-10-30', '2026-11-02'));
        Holiday::create(['name' => 'Working-day holiday', 'date' => '2026-11-02']);
        Holiday::create(['name' => 'Weekend holiday', 'date' => '2026-10-31']);
        $this->assertSame(1.0, $calendar->count('2026-10-30', '2026-11-02'));
        $this->assertSame(0.0, $calendar->count('2026-10-31', '2026-11-01'));
        $this->assertSame(0.5, $calendar->count('2026-10-30', '2026-10-30', 'am'));
        $this->assertSame(0.0, $calendar->count('2026-10-31', '2026-10-31', 'am'));
    }

    public function test_all_days_off_and_half_day_activation_are_rejected(): void
    {
        $this->actingAs($this->worker)->post('/leave-attendance/leave/apply', $this->leave(['starts_on' => '2026-10-31', 'ends_on' => '2026-11-01']))->assertSessionHasErrors('starts_on');
        $this->post('/leave-attendance/leave/apply', $this->leave(['session' => 'am']))->assertSessionHasErrors('session');
        $this->assertSame(0, LeaveApplication::count());
    }

    public function test_leave_approval_calendar_register_and_balance_exactly_once(): void
    {
        $p = $this->policy();
        Holiday::create(['name' => 'Test holiday', 'date' => '2026-11-02']);
        $this->actingAs($this->worker)->post('/leave-attendance/leave/apply', $this->leave())->assertSessionHasNoErrors();
        $leave = LeaveApplication::firstOrFail();
        $this->assertSame('1.00', $leave->chargeable_days);
        $this->assertSame('Absent', app(Register::class)->row($this->employee, '2026-10-28')['status']);
        $this->assertSame('In progress / future', app(Register::class)->row($this->employee, '2026-10-30')['status']);
        $this->actingAs($this->manager)->post('/leave-attendance/leave/'.$leave->id.'/decision', ['outcome' => 'approved', 'reason' => 'Reviewed'])->assertSessionHasNoErrors();
        $this->assertSame('Leave', app(Register::class)->row($this->employee, '2026-10-30')['status']);
        $this->assertSame('Day off', app(Register::class)->row($this->employee, '2026-10-31')['status']);
        $this->assertSame('Day off', app(Register::class)->row($this->employee, '2026-11-02')['status']);
        $this->assertSame(1.0, app(LeaveService::class)->used($this->employee, $p));
        $this->actingAs($this->hr)->post('/leave-attendance/leave/'.$leave->id.'/decision', ['outcome' => 'approved', 'reason' => 'Again'])->assertSessionHasErrors('decision');
        $this->assertSame(1.0, app(LeaveService::class)->used($this->employee, $p));
        $this->get('/leave-attendance?from=2026-10-30&to=2026-11-02&employee_id='.$this->employee->id)->assertOk()->assertSee('Leave')->assertSee('Day off');
    }

    public function test_policy_states_zero_unconfigured_and_as_needed_are_not_unlimited(): void
    {
        $service = app(LeaveService::class);
        $this->assertNotNull($service->accountingError($this->employee, null, '2026-10-30', '2026-10-30', 1));
        $p = $this->policy(['allowance' => 0]);
        $this->assertStringContainsString('Insufficient', $service->accountingError($this->employee, $p, '2026-10-30', '2026-10-30', 1));
        $p->update(['allowance_kind' => 'as_needed', 'allowance' => null]);
        $this->assertStringContainsString('pending', $service->accountingError($this->employee, $p, '2026-10-30', '2026-10-30', 1));
        $p->update(['allowance_kind' => 'unspecified']);
        $this->assertStringContainsString('unspecified', $service->accountingError($this->employee, $p, '2026-10-30', '2026-10-30', 1));
        $p->update(['allowance_kind' => 'numeric', 'allowance' => 5]);
        $this->assertNull($service->accountingError($this->employee, $p, '2026-10-30', '2026-10-30', 1));
    }

    public function test_annual_allowance_resets_and_cross_year_leave_is_charged_to_each_year(): void
    {
        $policy = $this->policy(['allowance' => 1]);
        $this->actingAs($this->worker)->post('/leave-attendance/leave/apply', $this->leave(['starts_on' => '2026-12-31', 'ends_on' => '2027-01-01']))->assertSessionHasNoErrors();
        $leave = LeaveApplication::firstOrFail();
        $this->actingAs($this->hr)->post('/leave-attendance/leave/'.$leave->id.'/decision', ['outcome' => 'approved', 'reason' => 'Two annual allowances'])->assertSessionHasNoErrors();
        $service = app(LeaveService::class);
        $this->assertSame(2.0, (float)$leave->fresh()->chargeable_days);
        $this->assertSame('calendar_year', $leave->fresh()->policy_snapshot['balance_basis']);
        foreach (['eligible', 'deduct_balance', 'period_start', 'period_end'] as $field) $this->assertArrayNotHasKey($field, $leave->fresh()->policy_snapshot);
        $this->assertSame(1.0, $service->used($this->employee, $policy, 2026));
        $this->assertSame(1.0, $service->used($this->employee, $policy, 2027));
        $this->assertSame(0.0, $service->used($this->employee, $policy, 2028));
        $this->assertStringContainsString('2027', $service->accountingError($this->employee, $policy, '2027-01-04', '2027-01-04', 1));
        $this->assertNull($service->accountingError($this->employee, $policy, '2028-01-03', '2028-01-03', 1));
        $this->get('/leave-attendance/balances?employee_id='.$this->employee->id.'&balance_year=2028')->assertOk()
            ->assertViewHas('balances', fn ($balances) => $balances[0]['used'] === 0.0);
        $this->get('/leave-attendance/setup/policies?employment_status_id='.$this->employee->employment_status_id.'&employee_id='.$this->employee->id.'&balance_year=2027')->assertOk()
            ->assertViewHas('policyRows', fn ($rows) => $rows->first()['remaining'] === 0.0);
        $this->get('/leave-attendance/balances?balance_year=invalid')->assertSessionHasErrors('balance_year');
    }

    public function test_cross_year_approval_revalidates_each_year_and_saves_nothing_on_insufficient_balance(): void
    {
        $this->policy(['allowance' => 1]);
        $this->actingAs($this->worker)->post('/leave-attendance/leave/apply', $this->leave(['starts_on' => '2026-12-31', 'ends_on' => '2027-01-01']))->assertSessionHasNoErrors();
        $crossYear = LeaveApplication::firstOrFail();
        $this->post('/leave-attendance/leave/apply', $this->leave(['starts_on' => '2027-01-04', 'ends_on' => '2027-01-04']))->assertSessionHasNoErrors();
        $other = LeaveApplication::latest('id')->firstOrFail();
        $this->actingAs($this->hr)->post('/leave-attendance/leave/'.$other->id.'/decision', ['outcome' => 'approved', 'reason' => 'First in 2027'])->assertSessionHasNoErrors();
        $this->post('/leave-attendance/leave/'.$crossYear->id.'/decision', ['outcome' => 'approved', 'reason' => 'Must check both years'])->assertSessionHasErrors('decision');
        $this->assertSame('pending', $crossYear->fresh()->status);
        $this->assertNull($crossYear->fresh()->policy_snapshot);
    }

    public function test_policy_fields_are_removed_from_schema_and_forms_while_approval_snapshots_are_preserved(): void
    {
        $policy = $this->policy();
        $snapshot = ['allowance' => 5, 'eligible' => true, 'deduct_balance' => true, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31'];
        $leave = LeaveApplication::create(['employee_id' => $this->employee->id, 'requester_id' => $this->worker->id, 'leave_type_id' => $this->type->id,
            'form_date' => '2026-01-01', 'starts_on' => '2026-10-29', 'ends_on' => '2026-10-29', 'chargeable_days' => 1, 'status' => 'approved',
            'reason' => 'Historical snapshot', 'policy_id' => $policy->id, 'policy_snapshot' => $snapshot]);
        $migration = require database_path('migrations/2026_11_02_002000_simplify_hrla_leave_policy.php');
        $migration->down();
        DB::table('hrla_leave_policies')->where('id', $policy->id)->update(['eligible' => false, 'deduct_balance' => false, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
        $migration->up();
        foreach (['eligible', 'deduct_balance', 'period_start', 'period_end'] as $field) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('hrla_leave_policies', $field));
        }
        $this->assertSame($snapshot, $leave->fresh()->policy_snapshot);
        $this->assertSame('5.00', $policy->fresh()->allowance);
        $response = $this->actingAs($this->hr)->get('/leave-attendance/setup/policies?employment_status_id='.$this->employee->employment_status_id)->assertOk();
        foreach (['eligible', 'deduct_balance', 'period_start', 'period_end'] as $field) $response->assertDontSee('name="'.$field.'"', false);
    }

    public function test_approval_revalidates_balance_holidays_and_policy_configuration(): void
    {
        $p = $this->policy(['allowance' => 2]);
        $this->actingAs($this->worker)->post('/leave-attendance/leave/apply', $this->leave())->assertSessionHasNoErrors();
        $first = LeaveApplication::firstOrFail();
        $this->post('/leave-attendance/leave/apply', $this->leave(['starts_on' => '2026-11-03', 'ends_on' => '2026-11-03']))->assertSessionHasNoErrors();
        $second = LeaveApplication::latest('id')->first();
        $this->actingAs($this->hr)->post('/leave-attendance/leave/'.$first->id.'/decision', ['outcome' => 'approved', 'reason' => 'First'])->assertSessionHasNoErrors();
        $this->post('/leave-attendance/leave/'.$second->id.'/decision', ['outcome' => 'approved', 'reason' => 'Second'])->assertSessionHasErrors('decision');
        $this->post('/leave-attendance/setup/holidays', ['name' => 'Would change balance', 'date' => '2026-11-02'])->assertSessionHasErrors('date');
        $this->assertSame(2.0, app(LeaveService::class)->used($this->employee, $p));
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_overlap_employee_scope_self_approval_and_hr_submissions_stay_pending(): void
    {
        $this->actingAs($this->worker)->post('/leave-attendance/leave/apply', $this->leave(['employee_id' => $this->other->id]))->assertForbidden();
        $this->actingAs($this->hr)->post('/leave-attendance/leave/apply', $this->leave())->assertSessionHasNoErrors();
        $leave = LeaveApplication::firstOrFail();
        $this->assertSame('pending', $leave->status);
        $this->post('/leave-attendance/leave/'.$leave->id.'/decision', ['outcome' => 'approved', 'reason' => 'Own submission'])->assertForbidden();
        $this->actingAs($this->worker)->post('/leave-attendance/leave/apply', $this->leave())->assertSessionHasErrors('starts_on');
        $this->actingAs($this->manager)->post('/leave-attendance/leave/'.$leave->id.'/decision', ['outcome' => 'approved', 'reason' => 'Incomplete policy'])->assertSessionHasErrors('decision');
    }

    public function test_secondary_reporting_requires_dual_flag_and_inactive_users_are_denied(): void
    {
        $this->assertTrue(app(LeaveAttendancePolicy::class)->manage($this->secondary, $this->employee));
        $this->employee->update(['dual_reporting' => false,'secondary_report_to_id'=>null]);
        $this->assertFalse(app(LeaveAttendancePolicy::class)->decide($this->secondary, $this->employee, $this->worker->id));
        $this->worker->update(['is_active' => false]);
        $this->actingAs($this->worker)->get('/leave-attendance')->assertRedirect('/login');
    }

    public function test_attendance_punch_dates_default_to_today_without_filling_blank_times(): void
    {
        $today = now()->toDateString();
        $this->actingAs($this->hr)->get('/leave-attendance/attendance/entry?direct=1&employee_id='.$this->employee->id)->assertOk()
            ->assertSee('name="time_in_date" value="'.$today.'"', false)
            ->assertSee('name="time_out_date" value="'.$today.'"', false);
        $data = $this->punches(['time_in' => null, 'time_out' => null, 'time_in_date' => $today, 'time_out_date' => $today, 'time_in_clock' => '9:00 AM', 'time_out_clock' => '', 'reason' => null]);
        $this->post('/leave-attendance/attendance/entry?direct=1', $data)->assertSessionHasNoErrors();
        $version = AttendanceVersion::firstOrFail();
        $this->assertSame($today.' 09:00:00', $version->time_in);
        $this->assertNull($version->time_out);
    }

    public function test_admin_remarks_remain_optional_but_employee_corrections_require_a_reason(): void
    {
        foreach (['missed_punch', 'client_meeting', 'correction'] as $index => $reason) {
            foreach ([true, false] as $direct) {
                $date = '2026-10-'.(20 + $index + ($direct ? 0 : 3));
                $data = $this->punches(['shift_date' => $date, 'time_in' => $date.' 09:00:00', 'time_out' => $date.' 17:00:00', 'reason_type' => $reason, 'client_name' => $reason === 'client_meeting' ? 'Client site' : null]);
                unset($data['reason']);
                if ($index === 1) $data['reason'] = null;
                if ($index === 2) $data['reason'] = '   ';
                $this->actingAs($direct ? $this->hr : $this->worker);
                if (! $direct) {
                    $this->post('/leave-attendance/attendance/entry', $data)->assertSessionHasErrors('reason');
                    $data['reason'] = 'Previous attendance needs correction';
                }
                $this->post('/leave-attendance/attendance/entry'.($direct ? '?direct=1' : ''), $data)->assertSessionHasNoErrors();
                $this->assertDatabaseHas($direct ? 'hrla_attendance_versions' : 'hrla_correction_requests', ['employee_id' => $this->employee->id, 'shift_date' => $date, 'reason_type' => $reason, 'reason' => $direct ? '' : $data['reason']]);
            }
        }
        $pending = CorrectionRequest::firstOrFail();
        $this->actingAs($this->hr)->post(route('la.corrections.decide', $pending), ['outcome' => 'approved', 'reason' => 'Reviewed'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hrla_attendance_versions', ['correction_id' => $pending->id, 'reason' => 'Previous attendance needs correction']);
    }

    public function test_attendance_other_reason_requires_nonblank_remarks_and_keeps_other_validation(): void
    {
        foreach ([true, false] as $direct) {
            $this->actingAs($direct ? $this->hr : $this->worker)->post('/leave-attendance/attendance/entry'.($direct ? '?direct=1' : ''), $this->punches(['reason_type' => 'other', 'reason' => " \n\t "]))->assertSessionHasErrors('reason');
        }
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
        $this->assertDatabaseCount('hrla_correction_requests', 0);
        $this->actingAs($this->hr)->post('/leave-attendance/attendance/entry?direct=1', $this->punches(['reason_type' => 'other', 'reason' => 'Explains the other reason']))->assertSessionHasNoErrors();
        $this->post('/leave-attendance/attendance/entry?direct=1', $this->punches(['reason_type' => 'correction', 'reason' => str_repeat('x', 2001)]))->assertSessionHasErrors('reason');
        $this->post('/leave-attendance/attendance/entry?direct=1', $this->punches(['reason_type' => 'client_meeting', 'reason' => null]))->assertSessionHasErrors('client_name');
        $this->assertFalse(session('errors')->has('reason'));
    }

    public function test_attendance_multi_select_filters_combine_choices_and_match_csv_export(): void
    {
        $a = \App\Models\Department::create(['code' => 'FILTER_A', 'name' => 'First filter department']);
        $b = \App\Models\Department::create(['code' => 'FILTER_B', 'name' => 'Second filter department']);
        $company = Company::create(['code' => 'FILTER_B', 'name' => 'Second filter company', 'status' => 'active']);
        $this->employee->update(['department_id' => $a->id,'primary_report_to_id'=>null,'secondary_report_to_id'=>null,'dual_reporting'=>false]);
        $this->other->update(['department_id' => $b->id]);
        $this->actingAs($this->hr)->post(route('la.setup.assignments.bulk'), ['employee_ids' => [$this->other->id, $this->boss->id], 'shift_id' => Shift::value('id'), 'starts_on' => '2026-01-01'])->assertSessionHasNoErrors();
        $service = app(AttendanceService::class);
        $service->append($this->employee, $this->hr, $this->punches(['shift_date' => '2026-10-28', 'time_in' => '2026-10-28 09:00:00', 'time_out' => '2026-10-28 17:00:00']), 'direct entry', null);
        $service->append($this->other, $this->hr, $this->punches(['employee_id' => $this->other->id, 'shift_date' => '2026-10-28', 'time_in' => '2026-10-28 10:00:00', 'time_out' => '2026-10-28 16:00:00']), 'direct entry', null);
        $query = ['from' => '2026-10-28', 'to' => '2026-10-28', 'company_id' => [$this->employee->company_id, $company->id], 'department_id' => [$a->id, $b->id], 'employee_id' => [$this->employee->id, $this->other->id], 'status' => ['On Time', 'Late']];
        $response = $this->get('/leave-attendance?'.http_build_query($query))->assertOk();
        $this->assertSame(2, $response->viewData('records')->total());
        $this->assertEqualsCanonicalizing(['On Time', 'Late / Early off'], collect($response->viewData('records')->items())->pluck('status')->all());
        $this->assertStringContainsString('name="employee_id[]"', $response->getContent());
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(2, $xpath->query('//input[@name="employee_id[]"][@checked]')->length);
        $this->assertSame(2, $xpath->query('//input[@name="status[]"][@checked]')->length);
        $csv = $this->get('/leave-attendance?'.http_build_query($query + ['export' => 1]))->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', preg_split('/\r?\n/', trim($csv)));
        $this->assertCount(3, $lines);
        $this->assertEqualsCanonicalizing(['WORKER', 'OTHER'], [$lines[1][0], $lines[2][0]]);
        $query['company_id'] = [$this->employee->company_id];
        $query['department_id'] = [$b->id];
        $this->get('/leave-attendance?'.http_build_query($query))->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1);
        $this->get('/leave-attendance?'.http_build_query(['from' => '2026-10-28', 'to' => '2026-10-28', 'employee_id' => $this->employee->id, 'status' => 'On Time']))->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1);
    }

    public function test_attendance_multi_select_pagination_retains_all_selections_and_checks_scope(): void
    {
        $query = ['from' => '2026-10-01', 'to' => '2026-10-31', 'employee_id' => [$this->employee->id, $this->other->id], 'company_id' => [$this->employee->company_id]];
        $response = $this->actingAs($this->hr)->get('/leave-attendance?'.http_build_query($query))->assertOk();
        $records = $response->viewData('records');
        $this->assertSame(62, $records->total());
        parse_str(parse_url($records->nextPageUrl(), PHP_URL_QUERY), $next);
        $this->assertSame(array_map('strval', $query['employee_id']), $next['employee_id']);
        $this->assertSame(array_map('strval', $query['company_id']), $next['company_id']);
        $this->get($records->nextPageUrl())->assertOk()->assertViewHas('records', fn ($records) => $records->currentPage() === 2 && count($records->items()) === 12);
        $this->actingAs($this->worker)->get('/leave-attendance?'.http_build_query($query))->assertForbidden();
        $this->get('/leave-attendance?'.http_build_query($query + ['export' => 1]))->assertForbidden();
        $this->actingAs($this->hr)->get('/leave-attendance?'.http_build_query(['employee_id' => ['bad'], 'company_id' => ['bad'], 'department_id' => ['bad'], 'status' => ['not a status']]))->assertSessionHasErrors(['employee_id.0', 'company_id.0', 'department_id.0', 'status.0']);
    }

    public function test_export_history_and_employee_selectors_enforce_scope_and_csv_is_escaped(): void
    {
        $this->other->update(['first_name' => '=HYPERLINK("bad")']);
        $this->actingAs($this->worker)->get('/leave-attendance?employee_id='.$this->other->id.'&export=1')->assertForbidden();
        $this->get('/leave-attendance/attendance/history/'.$this->other->id)->assertForbidden();
        $this->get('/leave-attendance/attendance/entry?direct=1&employee_id='.$this->other->id)->assertForbidden();
        $this->get('/leave-attendance/balances?employee_id='.$this->other->id)->assertForbidden();
        $this->get('/leave-attendance/import')->assertForbidden();
        $this->actingAs($this->hr);
        $csv = $this->get('/leave-attendance?employee_id='.$this->other->id.'&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_shift_snapshot_overnight_and_missing_shift_are_explicit(): void
    {
        $service = app(AttendanceService::class);
        $v = $service->append($this->employee, $this->hr, $this->punches(), 'direct entry', null);
        Shift::first()->update(['start_time' => '08:00:00']);
        $this->assertSame('2026-10-29 09:00:00', $service->latest($this->employee->id, '2026-10-29')->shift_start);
        $this->assertSame('Missing shift configuration', app(Register::class)->row($this->other, '2026-10-29')['status']);
        $shift = Shift::create(['name' => 'Night test', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'overnight' => true]);
        ShiftAssignment::create(['employee_id' => $this->other->id, 'shift_id' => $shift->id, 'starts_on' => '2026-01-01', 'actor_id' => $this->hr->id]);
        $service->append($this->other, $this->hr, $this->punches(['time_in' => '2026-10-29 22:00:00', 'time_out' => '2026-10-30 06:00:00']), 'direct entry', null);
        $r = app(Register::class)->row($this->other, '2026-10-29');
        $this->assertSame(28800, $r['work_seconds']);
        $this->assertSame('On Time', $r['status']);
    }

    public function test_import_mapping_errors_duplicate_rows_stale_rows_and_retry_are_safe(): void
    {
        $rows = [['row' => 2, 'cells' => ['WORKER', '2026-10-29', '2026-10-29 09:12:00', '2026-10-29 17:00:00']], ['row' => 3, 'cells' => ['UNKNOWN', '2026-10-29', '', '']], ['row' => 4, 'cells' => ['WORKER', '10/29/26', '', '']], ['row' => 5, 'cells' => ['WORKER', '2026-10-29', '', '']]];
        $batch = ImportBatch::create(['fingerprint' => str_repeat('a', 64), 'filename' => 'test.xlsx', 'actor_id' => $this->hr->id, 'headers' => ['Code', 'Date', 'Time In', 'Time Out'], 'rows' => $rows]);
        $service = app(ImportService::class);
        $mapping = $service->propose($batch->headers);
        $preview = $service->preview($batch, $mapping, $this->hr);
        $this->assertSame([], $preview[0]['errors']);
        $this->assertNotEmpty($preview[1]['errors']);
        $this->assertNotEmpty($preview[2]['errors']);
        $this->assertNotEmpty($preview[3]['errors']);
        $batch->update(['mapping' => $mapping, 'results' => $preview, 'status' => 'mapped']);
        $r = $service->commit($batch, $this->hr);
        $this->assertSame(1, $r['imported']);
        $this->assertSame(3, $r['skipped']);
        $this->assertSame(0, $r['failed']);
        $this->assertSame($r, $service->commit($batch, $this->hr));
        $this->assertSame(1, AttendanceVersion::count());
        $next = $batch->replicate();
        $next->fingerprint = str_repeat('b', 64);
        $next->status = 'mapped';
        $next->results = $preview;
        $next->save();
        $r = $service->commit($next, $this->hr);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(1, AttendanceVersion::count());
    }

    public function test_single_assignment_overlap_creates_new_priority(): void
    {
        $this->actingAs($this->hr)->post('/leave-attendance/setup/assignments', ['employee_id' => $this->employee->id, 'shift_id' => Shift::value('id'), 'starts_on' => '2026-10-29'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hrla_shift_assignments', 2);
        $this->assertSame(ShiftAssignment::max('id'), ShiftAssignment::where('employee_id', $this->employee->id)->effectiveOn('2026-10-29')->firstOrFail()->id);
    }

    public function test_inbox_filters_and_exports_are_scoped_and_preserve_request_values(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(18, 0));
        $this->actingAs($this->worker)->post('/leave-attendance/attendance/entry', $this->punches());
        $this->post('/leave-attendance/leave/apply', $this->leave());
        $this->get('/leave-attendance/corrections?employee_id='.$this->other->id.'&export=1')->assertForbidden();
        $this->get('/leave-attendance/leave?employee_id='.$this->other->id.'&export=1')->assertForbidden();
        $this->get('/leave-attendance/corrections?status=rejected')->assertOk()->assertSee('No correction requests');
        $csv = $this->get('/leave-attendance/corrections?status=pending&export=1')->assertOk()->streamedContent();
        // CSV clock presentation follows the requested AM/PM display; storage keeps original seconds.
        $this->assertStringContainsString('2026-10-29 9:16 AM', $csv);
        $csv = $this->get('/leave-attendance/leave?status=pending&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('Test leave', $csv);
    }

    public function test_configuration_deletion_is_scoped_and_protects_referenced_history(): void
    {
        $holiday = Holiday::create(['company_id'=>$this->employee->company_id,'name' => 'Disposable holiday', 'date' => '2026-12-25']);
        $this->actingAs($this->worker)->delete('/leave-attendance/setup/holidays/'.$holiday->id)->assertForbidden();
        $this->actingAs($this->hr)->delete('/leave-attendance/setup/holidays/'.$holiday->id)->assertSessionHasNoErrors();
        $this->assertNull($holiday->fresh());
        $this->delete('/leave-attendance/setup/shifts/'.Shift::value('id'))->assertSessionHasErrors('configuration');
        $this->assertSame(1, Shift::count());
    }

    public function test_module_migration_rollback_preserves_existing_records_and_empty_reinstall(): void
    {
        $employees = Employee::orderBy('id')->get()->toArray();
        $users = User::orderBy('id')->get()->toArray();
        $migration = require database_path('migrations/2026_10_29_001000_create_leave_attendance_module.php');
        $migration->down();
        $this->assertSame($employees, Employee::orderBy('id')->get()->toArray());
        $this->assertSame($users, User::orderBy('id')->get()->toArray());
        $this->actingAs($this->hr)->get('/')->assertOk();
        $this->get('/leave-attendance')->assertStatus(503)->assertSee('needs installation');
        $migration->up();
        foreach (['hrla_shifts', 'hrla_holidays', 'hrla_leave_types', 'hrla_leave_policies', 'hrla_attendance_versions'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
