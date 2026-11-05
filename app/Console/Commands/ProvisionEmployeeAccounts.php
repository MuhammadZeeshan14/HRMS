<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\EmployeeAccess;
use App\Services\PrivilegeService;
use App\Services\ReportingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProvisionEmployeeAccounts extends Command
{
    protected $signature = 'hrms:provision-accounts {--hr-department=* : Explicit database department IDs identifying HR membership} {--test-fixtures : Add isolated, labelled test employees} {--credentials= : Absolute path to the one-time XLSX handoff; resets all employee passwords} {--simple-testing-passwords : Use individual word-plus-number passwords for the testing export only} {--email-domain= : Assign unique employee company emails using this domain} {--node= : Bundled Node executable} {--audit= : Write data issues to this Markdown file}';

    protected $description = 'Provision linked employee accounts, optionally export individual temporary credentials, and audit reporting.';

    public function handle(AccountService $accounts, EmployeeAccess $access, ReportingService $reporting): int
    {
        $path = $this->option('credentials');
        if ($this->option('simple-testing-passwords') && ! $path) {
            $this->error('Simple testing passwords require an explicit --credentials workbook export.');

            return 1;
        }
        $domain = $this->option('email-domain');
        if ($domain && (! str_contains($domain, '.') || ! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            $this->error('Provide a valid email domain without @ or a URL.');

            return 1;
        }
        if ($path && (! preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx')) {
            $this->error('Provide an absolute XLSX output path.');

            return 1;
        }
        DB::transaction(function () use ($accounts, $access, $path, $domain) {
            foreach ($this->option('hr-department') as $id) {
                $department = Department::findOrFail($id);
                DB::table('departments')->where('id', $id)->update(['is_hr' => true]);
                app(AuditService::class)->record('department.hr.designated', Department::class, $department->id, [], ['is_hr' => true]);
            }
            foreach (Employee::orderBy('id')->get() as $employee) {
                $accounts->provision($employee);
            }
            if ($this->option('test-fixtures')) {
                $this->fixtures($accounts, $access);
            }
            if ($domain) {
                foreach (Employee::orderBy('id')->get() as $employee) {
                    $accounts->assignCompanyEmail($employee, $domain);
                }
            }
            if (! Employee::all()->contains(fn ($e) => $access->source($e) === 'Level 1' && $e->user_id)) {
                throw new \RuntimeException('No active linked Level 1 Admin exists. Link or create a legitimate root-department employee before quarantining legacy accounts.');
            }
            // Preserve orphan accounts for explicit ownership review, but grant them no login or legacy authority.
            foreach (User::doesntHave('employee')->get() as $orphan) {
                $orphan->forceFill(['is_active' => false, 'role' => 'employee'])->save();
                $accounts->revoke($orphan);
            }
            if ($path) {
                $rows = [];
                $issued = [];
                foreach (Employee::with(['company', 'department', 'user'])->orderBy('id')->get() as $e) {
                    do {
                        $password = $accounts->temporary($e, null, (bool) $this->option('simple-testing-passwords'));
                    } while (isset($issued[$password]));
                    $issued[$password] = true;
                    $user = $e->user->fresh();
                    if (! Hash::check($password, $user->password)) {
                        throw new \RuntimeException('Credential hash verification failed.');
                    }
                    $rows[] = [(string) $user->login_id, $e->employee_no !== $user->login_id ? $e->employee_no : '', $e->display_name,
                        $e->company?->name, $e->department?->name, $access->level($e), $access->source($e) ? 'Admin' : 'Employee',
                        $access->source($e) ?: '', $password, $access->enabled($user) ? 'Active · password change required' : 'INACTIVE · login blocked', $e->is_test_employee ? 'Test employee' : 'Existing employee', $e->email ?: ''];
                }
                $this->export($rows, $path);
            }
        }, 3);
        $issues = [];
        foreach (Employee::with('department')->get() as $e) {
            foreach ($reporting->errors($e) as $field => $message) {
                $issues[] = "| {$e->id} | {$e->employee_no} | {$field} | {$message} |";
            }
        }
        $orphans = User::doesntHave('employee')->pluck('id')->implode(', ');
        $audit = "# Existing data review\n\nReporting records were preserved. No reporting person was silently replaced.\n\n| Employee ID | Employee number | Field | Issue |\n|---|---|---|---|\n".implode("\n", $issues)."\n\nUnlinked users quarantined (inactive, no effective access): ".($orphans ?: 'None').".\n\nHR department IDs: ".Department::where('is_hr', true)->pluck('id')->implode(', ').".\n";
        if ($this->option('audit')) {
            file_put_contents($this->option('audit'), $audit);
        }
        $this->info('Provisioned '.Employee::count().' employee accounts; '.count($issues).' reporting/data issues.');

        return 0;
    }

    private function export(array $rows, string $path): void
    {
        $node = $this->option('node');
        if (! $node) {
            throw new \RuntimeException('Pass --node with the bundled Node executable. See docs/employee-access.md.');
        }
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $pipes = [];
        $process = proc_open([$node, base_path('tools/export-testing-credentials.mjs'), $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new \RuntimeException('Could not start workbook exporter.');
        }
        // Plaintext exists only in process memory and the requested XLSX, never intermediate files or output.
        fwrite($pipes[0], json_encode($rows, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('Workbook exporter failed. No account changes were committed. Check the bundled artifact runtime.');
        }
    }

    private function fixtures(AccountService $accounts, EmployeeAccess $access): void
    {
        $company = Employee::orderBy('id')->value('company_id');
        $departments = Department::where('company_id', $company)->where('status', 'active')->get();
        $root = $departments->first(fn ($d) => ! $d->parent_department_id);
        $branch = $departments->first(fn ($d) => $d->parent_department_id && $departments->contains('parent_department_id', $d->id) && $access->ancestors($d->id));
        if (! $root || ! $branch) {
            throw new \RuntimeException('Test fixtures require an existing root, branch, and leaf department.');
        }
        $leaf = $departments->firstWhere('parent_department_id', $branch->id);
        $make = function ($code, $department, $manager = null, $active = true) use ($company) {
            if ($e = Employee::where('employee_no', $code)->first()) {
                return $e;
            }

            return Employee::create(['employee_no' => $code, 'first_name' => 'Access Test', 'last_name' => $code, 'date_of_joining' => now()->toDateString(),
                'company_id' => $company, 'department_id' => $department, 'employment_status' => $active ? 'active' : 'inactive', 'employment_type' => 'permanent', 'is_test_employee' => true, 'primary_report_to_id' => $manager]);
        };
        $admin = $make('ACCESS-TEST-L1', $root->id);
        // The delegated fixture must belong to the test root's actual branch.
        $branch = $departments->firstWhere('parent_department_id', $root->id);
        $leaf = $branch ? $departments->firstWhere('parent_department_id', $branch->id) : null;
        if (! $branch || ! $leaf) {
            throw new \RuntimeException('Test root requires two descendant levels.');
        }
        $delegated = $make('ACCESS-TEST-DELEGATED', $branch->id, $admin->id);
        if (! $delegated->delegated_admin) {
            app(PrivilegeService::class)->change($admin->user, $delegated, true);
        }
        $make('ACCESS-TEST-EMPLOYEE', $leaf->id, $delegated->id);
        $make('ACCESS-TEST-INACTIVE', $leaf->id, $delegated->id, false);
    }
}
