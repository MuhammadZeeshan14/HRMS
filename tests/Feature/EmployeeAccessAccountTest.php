<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveAttendance\CorrectionRequest;
use App\Models\LeaveAttendance\Shift;
use App\Models\LeaveAttendance\ShiftAssignment;
use App\Models\User;
use App\Services\AccountService;
use App\Services\EmployeeAccess;
use App\Services\LeaveAttendance\AttendanceService;
use App\Services\MasterScope;
use App\Services\PrivilegeService;
use App\Services\ReportingService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EmployeeAccessAccountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $root;

    private Department $branch;

    private Department $leaf;

    private Department $other;

    private Employee $leader;

    private Employee $delegate;

    private Employee $worker;

    private Employee $hr;

    private Employee $second;

    private EmployeeAccess $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->access = app(EmployeeAccess::class);
        $this->company = Company::create(['code' => 'AC', 'name' => 'Access Company', 'status' => 'active']);
        $this->root = $this->department('ROOT');
        $this->branch = $this->department('BRANCH', $this->root->id);
        $this->leaf = $this->department('LEAF', $this->branch->id);
        $this->other = $this->department('OTHER', $this->root->id);
        $hrDepartment = $this->department('RENAMED-HR', $this->root->id, true);
        $this->leader = $this->employee('LEADER', $this->root);
        $this->delegate = $this->employee('DELEGATE', $this->branch, $this->leader);
        app(PrivilegeService::class)->change($this->leader->user, $this->delegate, true);
        $this->delegate->refresh();
        $this->second = $this->employee('SECOND', $this->root);
        $this->worker = $this->employee('WORKER', $this->leaf, $this->delegate);
        $this->hr = $this->employee('HR', $hrDepartment, $this->leader);
        $shift = Shift::create(['name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'overnight' => false, 'company_id' => $this->company->id]);
        foreach ([$this->worker, $this->leader, $this->delegate, $this->hr] as $e) {
            ShiftAssignment::create(['employee_id' => $e->id, 'shift_id' => $shift->id, 'starts_on' => '2026-01-01', 'actor_id' => $this->leader->user_id]);
        }
    }

    private function department(string $code, ?int $parent = null, bool $hr = false): Department
    {
        return Department::create(['code' => $code, 'name' => $code, 'company_id' => $this->company->id, 'parent_department_id' => $parent, 'is_hr' => $hr, 'status' => 'active']);
    }

    private function employee(string $number, Department $department, ?Employee $manager = null): Employee
    {
        $e = Employee::create(['employee_no' => $number, 'first_name' => 'Test', 'last_name' => $number, 'company_id' => $department->company_id, 'department_id' => $department->id,
            'date_of_joining' => '2026-01-01', 'employment_status' => 'active', 'employment_type' => 'permanent', 'primary_report_to_id' => $manager?->id]);
        $e->user->forceFill(['password' => Hash::make('Original!Password12'), 'must_change_password' => false])->save();

        return $e->refresh();
    }

    private function punches(array $extra = []): array
    {
        return $extra + ['employee_id' => $this->worker->id, 'shift_date' => '2026-11-03', 'base_version_id' => null, 'time_in' => '2026-11-03 09:00:00', 'time_out' => '2026-11-03 17:00:00', 'reason_type' => 'missed_punch', 'reason' => 'Verified correction'];
    }

    public function test_automatic_and_delegated_admin_access_ignores_legacy_roles(): void
    {
        $this->assertSame('Level 1', $this->access->source($this->leader));
        $this->assertSame('HR', $this->access->source($this->hr));
        $this->assertSame('delegated', $this->access->source($this->delegate));
        $this->assertNull($this->access->source($this->worker));
        $this->worker->user->update(['role' => 'admin']);
        $this->assertFalse($this->access->admin($this->worker->user));
        $orphan = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->assertFalse($this->access->admin($orphan));
        $this->hr->department->update(['name' => 'Any new name']);
        $this->assertTrue($this->access->hr($this->hr->user));
    }

    public function test_only_level_one_can_delegate_and_events_record_actor(): void
    {
        foreach ([$this->hr, $this->delegate, $this->worker] as $actor) {
            $this->actingAs($actor->user)->post(route('access.update', $this->worker), ['grant' => true, 'current_password' => 'Original!Password12'])->assertForbidden();
        }
        $this->actingAs($this->leader->user)->post(route('access.update', $this->worker), ['grant' => true, 'current_password' => 'Original!Password12'])->assertSessionHasNoErrors();
        $this->assertSame('delegated', $this->access->source($this->worker->fresh()));
        $this->assertDatabaseHas('account_access_events', ['employee_id' => $this->worker->id, 'actor_id' => $this->leader->user_id, 'action' => 'admin.granted']);
        $this->post(route('access.update', $this->leader), ['grant' => true, 'current_password' => 'Original!Password12'])->assertForbidden();
    }

    public function test_reporting_only_accepts_actual_ancestor_admins_and_same_company(): void
    {
        $reporting = app(ReportingService::class);
        $this->assertEqualsCanonicalizing([$this->leader->id, $this->second->id, $this->delegate->id], $reporting->candidates($this->worker)->pluck('id')->all());
        $cross = $this->employee('CROSS', $this->other, $this->leader);
        app(PrivilegeService::class)->change($this->leader->user, $cross, true);
        $this->assertFalse($reporting->eligible($this->worker, $cross->fresh()));
        $this->assertFalse($reporting->eligible($this->worker, $this->hr));
        $company = Company::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $foreign = Department::create(['code' => 'FOREIGN', 'name' => 'Foreign', 'company_id' => $company->id, 'status' => 'active']);
        $foreignAdmin = $this->employee('FOREIGN', $foreign);
        $this->assertFalse($reporting->eligible($this->worker, $foreignAdmin));
        $this->assertFalse($this->access->canWrite($this->leader->user, $foreignAdmin));
        $this->assertTrue($this->access->canWrite($this->delegate->user, $cross));
        $this->assertTrue($this->access->canWrite($this->hr->user, $cross));
    }

    public function test_model_write_paths_validate_primary_secondary_self_duplicates_and_cycles(): void
    {
        foreach ([['primary_report_to_id' => $this->worker->id], ['primary_report_to_id' => $this->hr->id], ['dual_reporting' => true, 'secondary_report_to_id' => $this->delegate->id]] as $data) {
            try {
                $this->worker->fresh()->update($data);
                $this->fail('Invalid reporting accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->worker->update(['dual_reporting' => true, 'secondary_report_to_id' => $this->second->id]);
        $this->assertSame($this->second->id, $this->worker->fresh()->secondary_report_to_id);
        $this->expectException(ValidationException::class);
        $this->leader->update(['primary_report_to_id' => $this->worker->id]);
    }

    public function test_root_can_have_no_manager_but_non_root_requires_eligible_manager(): void
    {
        app(ReportingService::class)->validate($this->leader);
        $this->expectException(ValidationException::class);
        $this->employee('MISSING', $this->leaf);
    }

    public function test_employee_routes_and_both_sidebars_hide_administration(): void
    {
        $this->actingAs($this->worker->user)->get('/')->assertOk()->assertSee('Employee Dashboard')->assertDontSee('HRIS Setup')->assertDontSee('Admin Privileges')->assertDontSee('Create Employee');
        foreach (['/employees', '/employees/create', '/company-department', '/master-data/companies', '/leave-attendance/setup', '/leave-attendance/import', '/access-management'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/leave-attendance/dashboard')->assertOk()->assertDontSee('HRLA Setup')->assertDontSee('Attendance Upload')->assertSee('My Account');
        $this->get(route('employees.edit', $this->leader))->assertForbidden();
        $this->get(route('employees.show', $this->worker))->assertOk();
    }

    public function test_scoped_directory_reporting_selector_and_setup_assignments(): void
    {
        $cross = $this->employee('CROSS', $this->other, $this->leader);
        $this->actingAs($this->delegate->user)->get('/employees')->assertOk()->assertSee('WORKER')->assertSee('CROSS');
        $this->get('/reporting-candidates?company_id='.$this->company->id.'&department_id='.$this->leaf->id)->assertOk()->assertJsonCount(3, 'employees');
        $this->get('/reporting-candidates?company_id='.$this->company->id.'&department_id='.$this->other->id)->assertOk()->assertJsonCount(2, 'employees');
        $this->post('/leave-attendance/setup/assignments/bulk', ['employee_ids' => [$cross->id], 'shift_id' => Shift::first()->id, 'starts_on' => '2026-11-04'])->assertSessionHasNoErrors();
    }

    public function test_admin_entry_commits_immediately_without_direct_flag_including_own(): void
    {
        foreach ([$this->worker, $this->delegate] as $e) {
            $this->actingAs($this->delegate->user)->post(route('la.attendance.store'), $this->punches(['employee_id' => $e->id]))->assertSessionHasNoErrors();
            $this->assertDatabaseHas('hrla_attendance_versions', ['employee_id' => $e->id, 'actor_id' => $this->delegate->user_id, 'source' => 'direct entry']);
        }
        $this->assertDatabaseCount('hrla_correction_requests', 0);
        $this->assertDatabaseCount('hrla_attendance_versions', 2);
    }

    public function test_all_admin_sources_can_manage_other_branches_but_not_change_reporting_rules_or_delegate(): void
    {
        $cross = $this->employee('CROSS-BRANCH', $this->other, $this->leader);
        ShiftAssignment::create(['employee_id' => $cross->id, 'shift_id' => Shift::first()->id, 'starts_on' => '2026-01-01', 'actor_id' => $this->leader->user_id]);
        foreach ([$this->delegate, $this->hr, $this->leader] as $admin) {
            $this->assertTrue($this->access->configure($admin->user));
            $this->assertTrue($this->access->canWrite($admin->user, $cross));
            $this->actingAs($admin->user)->get(route('employees.edit', $cross))->assertOk();
            $this->get('/master-data/companies')->assertOk();
            $this->get('/leave-attendance/setup/holidays')->assertOk();
        }
        $this->actingAs($this->delegate->user)->post(route('la.attendance.store'), $this->punches(['employee_id' => $cross->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hrla_attendance_versions', ['employee_id' => $cross->id, 'actor_id' => $this->delegate->user_id]);
        $this->assertDatabaseCount('hrla_correction_requests', 0);
        $this->assertFalse(app(ReportingService::class)->eligible($cross, $this->delegate));
        $this->post(route('access.update', $cross), ['grant' => true, 'current_password' => 'Original!Password12'])->assertForbidden();
        $this->get(route('access.index'))->assertForbidden();
        $this->get('/master-data/departments')->assertOk();
        app(MasterScope::class)->write('departments', ['company_id' => $this->company->id, 'name' => 'Renamed other branch'], $this->other->id);
        $this->expectException(HttpException::class);
        app(MasterScope::class)->write('departments', ['company_id' => $this->company->id, 'parent_department_id' => null], $this->branch->id);
    }

    public function test_employee_pending_routes_notifications_and_commits_only_after_approval(): void
    {
        $this->actingAs($this->worker->user)->post(route('la.attendance.store'), $this->punches())->assertSessionHasNoErrors();
        $request = CorrectionRequest::firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
        $this->assertSame(1, $this->delegate->user->notifications()->count());
        $this->assertSame(1, $this->hr->user->notifications()->count());
        $this->actingAs($this->delegate->user)->get(route('la.corrections'))->assertOk()->assertSee('WORKER');
        $this->post(route('la.corrections.decide', $request), ['outcome' => 'approved', 'reason' => 'Checked'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hrla_attendance_versions', ['correction_id' => $request->id]);
        $this->post(route('la.corrections.decide', $request), ['outcome' => 'approved', 'reason' => 'Again'])->assertSessionHasErrors('decision');
        $this->assertDatabaseCount('hrla_attendance_versions', 1);
    }

    public function test_employee_cannot_forge_direct_or_other_employee_requests(): void
    {
        $this->actingAs($this->worker->user)->post(route('la.attendance.store'), $this->punches(['direct' => 1]))->assertForbidden();
        $this->post(route('la.attendance.store'), $this->punches(['employee_id' => $this->leader->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hrla_correction_requests', ['employee_id' => $this->worker->id]);
        $this->assertDatabaseMissing('hrla_correction_requests', ['employee_id' => $this->leader->id]);
    }

    public function test_rejection_self_approval_and_unauthorized_decisions_preserve_attendance(): void
    {
        $service = app(AttendanceService::class);
        $request = $service->request($this->worker, $this->worker->user, $this->punches());
        $this->actingAs($this->worker->user)->post(route('la.corrections.decide', $request), ['outcome' => 'approved', 'reason' => 'Mine'])->assertForbidden();
        $this->actingAs($this->leader->user)->post(route('la.corrections.decide', $request), ['outcome' => 'approved', 'reason' => 'Not assigned'])->assertForbidden();
        $this->actingAs($this->hr->user)->post(route('la.corrections.decide', $request), ['outcome' => 'rejected', 'reason' => 'Missing evidence'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
    }

    public function test_stale_approval_cannot_overwrite_new_attendance(): void
    {
        $service = app(AttendanceService::class);
        $request = $service->request($this->worker, $this->worker->user, $this->punches());
        $service->append($this->worker, $this->hr->user, $this->punches(), 'direct entry', null);
        $this->actingAs($this->delegate->user)->post(route('la.corrections.decide', $request), ['outcome' => 'approved', 'reason' => 'Stale'])->assertSessionHasErrors('base_version_id');
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertDatabaseCount('hrla_attendance_versions', 1);
    }

    public function test_permissions_rechecked_after_reassignment_and_revocation(): void
    {
        $service = app(AttendanceService::class);
        $request = $service->request($this->worker, $this->worker->user, $this->punches());
        $this->worker->update(['primary_report_to_id' => $this->second->id]);
        app(PrivilegeService::class)->change($this->leader->user, $this->delegate, false);
        $this->assertFalse($this->access->admin($this->delegate->user));
        $this->actingAs($this->delegate->user)->post(route('la.corrections.decide', $request), ['outcome' => 'approved', 'reason' => 'Old approver'])->assertForbidden();
    }

    public function test_revocation_and_department_changes_block_new_invalid_relationships(): void
    {
        try {
            app(PrivilegeService::class)->change($this->leader->user, $this->delegate, false);
            $this->fail('Revocation accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reporting', $e->errors());
        }
        $this->assertTrue((bool) $this->delegate->fresh()->delegated_admin);
        try {
            $this->delegate->update(['department_id' => $this->other->id]);
            $this->fail('Transfer accepted');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->other->update(['parent_department_id' => null]);
        try {
            $this->branch->update(['parent_department_id' => $this->other->id]);
            $this->fail('Hierarchy edit accepted');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertSame($this->root->id, $this->branch->fresh()->parent_department_id);
        app(PrivilegeService::class)->change($this->leader->user, $this->delegate, false, [$this->worker->id => ['primary_report_to_id' => $this->second->id]]);
        $this->assertFalse((bool) $this->delegate->fresh()->delegated_admin);
    }

    public function test_new_and_existing_employee_accounts_have_unique_login_ids_and_no_duplicates(): void
    {
        $this->assertSame('WORKER', $this->worker->user->login_id);
        $count = User::count();
        app(AccountService::class)->provision($this->worker);
        $this->assertSame($count, User::count());
        $existing = Employee::withoutEvents(fn () => Employee::create(['employee_no' => 'LEGACY SPACE', 'first_name' => 'Legacy', 'last_name' => 'Employee', 'company_id' => $this->company->id, 'department_id' => $this->root->id, 'date_of_joining' => '2026-01-01', 'employment_status' => 'active']));
        $u = app(AccountService::class)->provision($existing);
        $this->assertSame('EMP-'.$existing->id, $u->login_id);
        $this->assertSame($u->id, $existing->fresh()->user_id);
    }

    public function test_login_employee_id_inactive_denial_and_optional_password_change(): void
    {
        $temporary = app(AccountService::class)->temporary($this->worker);
        $this->post('/login', ['login_id' => 'WORKER', 'password' => $temporary])->assertRedirect(route('dashboard'));
        $this->get('/')->assertOk()->assertSee('Employee Dashboard');
        $this->get('/leave-attendance/dashboard')->assertOk();
        $this->post(route('account.password'), ['current_password' => $temporary, 'password' => 'Changed!Password456', 'password_confirmation' => 'Changed!Password456'])->assertSessionHasNoErrors();
        $this->get('/')->assertOk();
        $this->post('/logout');
        $this->worker->update(['employment_status' => 'inactive']);
        $this->post('/login', ['login_id' => 'WORKER', 'password' => 'Changed!Password456'])->assertSessionHasErrors('login_id');
        $this->assertGuest();
    }

    public function test_login_accepts_assigned_company_email_and_recovery_email_case_insensitively(): void
    {
        $email = app(AccountService::class)->assignCompanyEmail($this->worker, 'ahamson.com');
        $this->worker->user->forceFill(['email' => 'verified@example.test', 'email_verified_at' => now()])->save();
        foreach ([$email, strtoupper($email), 'verified@example.test', 'WORKER'] as $identifier) {
            $this->post('/login', ['login_id' => ' '.$identifier.' ', 'password' => 'Original!Password12'])->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs($this->worker->user);
            $this->post('/logout');
        }
        $this->worker->update(['employment_status' => 'inactive']);
        $this->post('/login', ['login_id' => $email, 'password' => 'Original!Password12'])->assertSessionHasErrors('login_id');
        $this->assertGuest();
    }

    public function test_email_login_rejects_wrong_password_pending_email_and_ambiguous_addresses(): void
    {
        $email = app(AccountService::class)->assignCompanyEmail($this->worker, 'ahamson.com');
        $this->post('/login', ['login_id' => $email, 'password' => 'wrong'])->assertSessionHasErrors('login_id');
        $this->worker->user->forceFill(['pending_recovery_email' => 'pending@example.test'])->save();
        $this->post('/login', ['login_id' => 'pending@example.test', 'password' => 'Original!Password12'])->assertSessionHasErrors('login_id');
        $this->leader->forceFill(['email' => $email])->save();
        $this->post('/login', ['login_id' => $email, 'password' => 'Original!Password12'])->assertSessionHasErrors('login_id');
        $this->assertGuest();
    }

    public function test_temporary_password_users_can_manage_account_but_keep_employee_permissions(): void
    {
        app(AccountService::class)->temporary($this->worker);
        $this->actingAs($this->worker->user->fresh())->get(route('account.show'))->assertOk()->assertSee('You can continue to other pages')->assertSee('Save personal information');
        $this->post(route('account.profile'), ['phone' => '+923001234567'])->assertSessionHasNoErrors();
        $this->get('/employees')->assertForbidden();
        $this->get('/leave-attendance/setup')->assertForbidden();
    }

    public function test_account_updates_cannot_change_identity_or_privileges_and_sensitive_changes_require_password(): void
    {
        $this->actingAs($this->worker->user)->post(route('account.profile'), ['phone' => '+923001234567', 'department_id' => $this->root->id, 'delegated_admin' => 1, 'company_id' => 999, 'login_id' => 'PROMOTED', 'user_id' => $this->leader->user_id])->assertSessionHasNoErrors();
        $this->assertSame($this->leaf->id, $this->worker->fresh()->department_id);
        $this->assertSame('WORKER', $this->worker->user->fresh()->login_id);
        $this->assertFalse($this->access->admin($this->worker->user));
        $this->post(route('account.password'), ['current_password' => 'wrong', 'password' => 'Changed!Password456', 'password_confirmation' => 'Changed!Password456'])->assertSessionHasErrors('current_password');
        $this->post(route('account.sessions'), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    }

    public function test_assisted_reset_revokes_sessions_is_scoped_and_audited_without_password(): void
    {
        $old = $this->worker->user->session_version;
        $this->actingAs($this->delegate->user)->post(route('employees.password', $this->worker), ['current_password' => 'Original!Password12'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Temporary password issued');
        $this->assertGreaterThan($old, $this->worker->user->fresh()->session_version);
        $this->assertTrue((bool) $this->worker->user->fresh()->must_change_password);
        $this->assertDatabaseHas('account_access_events', ['employee_id' => $this->worker->id, 'actor_id' => $this->delegate->user_id, 'action' => 'password.reset.assisted', 'details' => null]);
        $this->actingAs($this->worker->user)->withSession(['account_version' => $old])->get('/')->assertRedirect('/login');
    }

    public function test_recovery_requires_email_verification_and_password_reset_token(): void
    {
        Notification::fake();
        $this->actingAs($this->worker->user)->post(route('account.recovery'), ['current_password' => 'Original!Password12', 'email' => 'recovery@example.test'])->assertSessionHasNoErrors();
        $u = $this->worker->user->fresh();
        $this->assertNull($u->email_verified_at);
        $this->assertNotSame('recovery@example.test', $u->email);
        $this->get(URL::temporarySignedRoute('account.recovery.verify', now()->addMinutes(30), ['user' => $u->id, 'email_hash' => hash('sha256', 'recovery@example.test')]))->assertRedirect(route('account.show'));
        $u->refresh();
        $this->assertSame('recovery@example.test', $u->email);
        $this->assertNotNull($u->email_verified_at);
        $this->post('/logout');
        $token = Password::createToken($u);
        $this->post('/reset-password', ['email' => $u->email, 'token' => $token, 'password' => 'Recovered!Password123', 'password_confirmation' => 'Recovered!Password123'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('Recovered!Password123', $u->fresh()->password));
        $this->post('/reset-password', ['email' => $u->email, 'token' => $token, 'password' => 'Recovered!Password456', 'password_confirmation' => 'Recovered!Password456'])->assertSessionHasErrors('email');
    }

    public function test_cross_company_urls_exports_downloads_and_configuration_writes_are_denied(): void
    {
        $company = Company::create(['code' => 'FOREIGN', 'name' => 'Foreign Company', 'status' => 'active']);
        $department = Department::create(['code' => 'FOREIGN', 'name' => 'Foreign', 'company_id' => $company->id, 'status' => 'active']);
        $employee = $this->employee('FOREIGN', $department);
        $foreignShift = Shift::create(['name' => 'Foreign shift', 'start_time' => '10:00:00', 'end_time' => '18:00:00', 'overnight' => false, 'company_id' => $company->id]);
        $doc = DB::table('hr_documents')->insertGetId(['name' => 'Foreign private document', 'company_id' => $company->id, 'document_type_id' => DB::table('document_types')->insertGetId(['name' => 'Foreign type']), 'document_file' => 'private.txt']);
        $this->actingAs($this->hr->user);
        $this->get(route('employees.show', $employee))->assertForbidden();
        $this->get('/leave-attendance?export=1&employee_id[]='.$employee->id)->assertForbidden();
        $this->get(route('la.history', $employee))->assertForbidden();
        $this->get('/master-data/hr_documents/'.$doc.'/files/document_file')->assertNotFound();
        $this->put('/leave-attendance/setup/shifts/'.$foreignShift->id, ['name' => 'Hijacked', 'start_time' => '09:00', 'end_time' => '17:00', 'overnight' => 0])->assertNotFound();
        $this->post('/leave-attendance/setup/assignments/bulk', ['employee_ids' => [$employee->id], 'shift_id' => Shift::first()->id, 'starts_on' => '2026-11-01'])->assertForbidden();
        $this->get('/company-department?company_id='.$company->id)->assertForbidden();
        $this->get('/master-data/companies')->assertOk()->assertDontSee('Foreign Company');
    }

    public function test_no_eligible_approver_gives_actionable_error_and_creates_no_pending_request(): void
    {
        DB::table('departments')->where('id', $this->hr->department_id)->update(['is_hr' => false]);
        DB::table('employees')->where('id', $this->worker->id)->update(['primary_report_to_id' => null]);
        $this->actingAs($this->worker->user)->post(route('la.attendance.store'), $this->punches())->assertSessionHasErrors('employee_id');
        $this->assertDatabaseCount('hrla_correction_requests', 0);
    }

    public function test_promoting_requester_does_not_allow_self_approval(): void
    {
        $request = app(AttendanceService::class)->request($this->worker, $this->worker->user, $this->punches());
        app(PrivilegeService::class)->change($this->leader->user, $this->worker, true);
        $this->actingAs($this->worker->user)->post(route('la.corrections.decide', $request), ['outcome' => 'approved', 'reason' => 'Now Admin'])->assertForbidden();
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
    }

    public function test_transfers_recalculate_scope_and_account_deactivation_revokes_access(): void
    {
        $cross = $this->employee('CROSS', $this->other, $this->leader);
        $this->worker->update(['primary_report_to_id' => $this->second->id]);
        $this->delegate->update(['department_id' => $this->other->id]);
        $this->assertTrue($this->access->canWrite($this->delegate->user, $this->worker));
        $this->assertTrue($this->access->canWrite($this->delegate->user, $cross));
        $this->worker->user->update(['is_active' => false]);
        $this->assertFalse($this->access->enabled($this->worker->user));
        $this->post('/login', ['login_id' => 'WORKER', 'password' => 'Original!Password12'])->assertSessionHasErrors('login_id');
    }

    public function test_forgot_password_only_sends_to_verified_active_email_and_verification_is_account_bound(): void
    {
        Notification::fake();
        $u = $this->worker->user;
        $this->post('/forgot-password', ['email' => $u->email])->assertSessionHasNoErrors();
        Notification::assertNothingSent();
        $u->forceFill(['email_verified_at' => now()])->save();
        $this->post('/forgot-password', ['email' => $u->email])->assertSessionHasNoErrors();
        Notification::assertSentTo($u, ResetPassword::class);
        $u->forceFill(['pending_recovery_email' => 'pending@example.test'])->save();
        $url = URL::temporarySignedRoute('account.recovery.verify', now()->addMinutes(30), ['user' => $u->id, 'email_hash' => hash('sha256', 'pending@example.test')]);
        $this->actingAs($this->leader->user)->get($url)->assertForbidden();
        $this->actingAs($u)->get($url.'&tampered=1')->assertForbidden();
    }

    public function test_login_id_collisions_and_provisioning_existing_records_preserve_legitimate_admin_access(): void
    {
        $orphan = User::factory()->create(['role' => 'admin', 'is_active' => true, 'login_id' => 'COLLISION']);
        $e = $this->employee('COLLISION', $this->root);
        $this->assertSame('COLLISION-1', $e->user->login_id);
        $legacy = Employee::withoutEvents(fn () => Employee::create(['employee_no' => 'OLD-UNLINKED', 'first_name' => 'Existing', 'last_name' => 'Unlinked', 'company_id' => $this->company->id, 'department_id' => $this->root->id, 'date_of_joining' => '2026-01-01', 'employment_status' => 'active']));
        $this->artisan('hrms:provision-accounts')->assertSuccessful();
        $this->assertNotNull($legacy->fresh()->user_id);
        $this->assertFalse($orphan->fresh()->is_active);
        $this->assertTrue($this->access->admin($this->leader->user));
        $count = User::count();
        $this->artisan('hrms:provision-accounts')->assertSuccessful();
        $this->assertSame($count, User::count());
    }

    public function test_secondary_reporting_uses_identical_company_and_ancestor_validation(): void
    {
        $candidate = clone $this->worker;
        $candidate->dual_reporting = true;
        $candidate->secondary_report_to_id = $this->hr->id;
        $this->assertArrayHasKey('secondary_report_to_id', app(ReportingService::class)->errors($candidate));
        $candidate->secondary_report_to_id = $this->second->id;
        $this->assertSame([], app(ReportingService::class)->errors($candidate));
        $this->actingAs($this->delegate->user)->get('/leave-attendance/setup/holidays')->assertOk();
        $this->get('/master-data/companies')->assertOk();
        $this->get('/leave-attendance/setup')->assertOk()->assertSee('href="'.route('la.setup', 'holidays').'"', false);
    }

    public function test_simple_testing_passwords_work_and_allow_navigation_before_password_change(): void
    {
        $password = app(AccountService::class)->temporary($this->worker, null, true);
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{3,6}[0-9]{4}$/D', $password);
        $this->assertTrue(Hash::check($password, $this->worker->user->fresh()->password));
        $this->post('/login', ['login_id' => 'WORKER', 'password' => $password])->assertRedirect(route('dashboard'));
        $this->get('/')->assertOk()->assertSee('Employee Dashboard');
        $this->get('/leave-attendance/dashboard')->assertOk();
        $this->assertTrue($this->worker->user->fresh()->must_change_password);
    }

    public function test_simple_testing_mode_requires_explicit_export_and_does_not_weaken_assisted_resets(): void
    {
        $hash = $this->worker->user->password;
        $this->artisan('hrms:provision-accounts', ['--simple-testing-passwords' => true])->assertExitCode(1);
        $this->assertSame($hash, $this->worker->user->fresh()->password);
        $this->expectException(HttpException::class);
        app(AccountService::class)->temporary($this->worker, $this->leader->user, true);
    }

    public function test_company_email_assignment_is_unique_idempotent_and_not_automatically_verified(): void
    {
        $accounts = app(AccountService::class);
        $first = $accounts->assignCompanyEmail($this->worker, 'ahamson.com');
        $duplicate = Employee::create(['employee_no' => 'SAME-NAME', 'first_name' => $this->worker->first_name, 'last_name' => $this->worker->last_name, 'company_id' => $this->company->id, 'department_id' => $this->root->id, 'date_of_joining' => '2026-01-01', 'employment_status' => 'active']);
        $second = $accounts->assignCompanyEmail($duplicate, 'ahamson.com');
        $this->assertSame('test.worker@ahamson.com', $first);
        $this->assertNotSame($first, $second);
        $this->assertSame($first, $accounts->assignCompanyEmail($this->worker, 'ahamson.com'));
        $this->assertSame($first, $this->worker->fresh()->email);
        $this->assertSame($first, $this->worker->user->fresh()->email);
        $this->assertNull($this->worker->user->fresh()->email_verified_at);
        $this->assertDatabaseHas('account_access_events', ['employee_id' => $this->worker->id, 'action' => 'email.company.assigned']);
        $this->assertFalse($this->access->admin($this->worker->user));
    }

    public function test_company_email_assignment_preserves_verified_recovery_email_until_verification(): void
    {
        $u = $this->worker->user;
        $old = $u->email;
        $u->forceFill(['email_verified_at' => now()])->save();
        $email = app(AccountService::class)->assignCompanyEmail($this->worker, 'ahamson.com');
        $this->assertSame($old, $u->fresh()->email);
        $this->assertSame($email, $u->fresh()->pending_recovery_email);
        $this->assertNotNull($u->fresh()->email_verified_at);
        $this->expectException(ValidationException::class);
        app(AccountService::class)->assignCompanyEmail($this->worker, 'https://invalid.test');
    }
}
