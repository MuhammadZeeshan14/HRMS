<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeaveAttendanceCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        \App\Models\User::query()->update(['must_change_password'=>false]);
    }

    public function test_module_migration_round_trip_preserves_all_representative_existing_tables(): void
    {
        $tables = collect(Schema::getTables())->pluck('name')->filter(fn ($name) => ! str_starts_with($name, 'hrla_') && ! str_starts_with($name, 'sqlite_'));
        $snapshot = fn () => $tables->mapWithKeys(fn ($name) => [$name => hash('sha256', json_encode(DB::table($name)->get()->toArray()))])->all();
        $before = $snapshot();
        $migration = require database_path('migrations/2026_10_29_001000_create_leave_attendance_module.php');
        $migration->down();
        $this->assertSame($before, $snapshot());
        $migration->up();
        $this->assertSame($before, $snapshot());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(0, DB::table('hrla_leave_policies')->count());
    }

    public function test_real_login_logout_and_role_navigation_with_module_installed(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $this->get('/login')->assertOk();
        $this->post('/login', ['login_id' => $admin->login_id, 'password' => 'invalid'])->assertSessionHasErrors('login_id');
        $admin->update(['password' => 'isolated-test-password']);
        $this->post('/login', ['login_id' => $admin->login_id, 'password' => 'isolated-test-password'])->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee('Leave &amp; Attendance', false);
        $this->post('/logout')->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
        foreach (['admin', 'hr_manager', 'employee'] as $role) {
            $this->actingAs(User::where('role', $role)->firstOrFail())->get('/')->assertOk()->assertSee('Leave &amp; Attendance', false);
        }
    }

    public function test_all_current_master_lists_and_company_department_render(): void
    {
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        foreach (config('hris_setup') as $key => $definition) {
            if (is_array($definition) && isset($definition['fields'])) {
                $this->get('/master-data/'.$key)->assertOk();
            }
        }
        $this->get('/company-department')->assertOk();
        $employee = Employee::firstOrFail();
        $this->get('/employees/'.$employee->id)->assertOk();
        $this->get('/employees/'.$employee->id.'/edit')->assertOk();
        $this->get('/employees/'.$employee->id.'/exit')->assertOk();
        $this->actingAs(User::where('role', 'employee')->firstOrFail())->get('/company-department')->assertForbidden();
    }

    public function test_master_crud_validation_and_protected_document_download(): void
    {
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        $this->post('/master-data/companies', ['name' => 'No required code'])->assertSessionHasErrors('code');
        $this->post('/master-data/companies', ['name'=>'Outside company','code'=>'COMPAT','status'=>'active'])->assertForbidden();
        $id=auth()->user()->employee->company_id;
        $this->put('/master-data/companies/'.$id,['name'=>'Updated compatibility fixture','code'=>'COMPAT','status'=>'active'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('companies',['id'=>$id,'name'=>'Updated compatibility fixture']);
        Storage::fake('local');
        $definition = config('hris_setup.hr_documents');
        $fileFields = array_keys(array_filter($definition['fields'], fn ($f) => ($f['type'] ?? null) === 'file'));
        $this->assertNotEmpty($fileFields);
        $field = $fileFields[0];
        $path = 'hris-setup/compatibility.txt';
        Storage::disk('local')->put($path, 'Isolated compatibility fixture');
        $data = ['name' => 'Compatibility document', $field => $path];
        // Satisfy only required foreign keys in the existing document schema.
        if (Schema::hasColumn('hr_documents', 'document_type_id')) {
            $data['document_type_id'] = DB::table('document_types')->insertGetId(['name' => 'Compatibility document type', 'file_extensions' => 'txt']);
        }
        $doc = DB::table('hr_documents')->insertGetId($data);
        $this->get('/master-data/hr_documents/'.$doc.'/files/'.$field)->assertOk()->assertDownload();
        $this->actingAs(User::where('role', 'employee')->firstOrFail())->get('/master-data/hr_documents/'.$doc.'/files/'.$field)->assertForbidden();
    }
}
