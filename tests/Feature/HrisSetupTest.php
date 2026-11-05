<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HrisSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        User::query()->update(['must_change_password' => false]);
        $this->actingAs(User::where('role', 'admin')->first());
    }

    public function test_every_setup_form_renders_and_other_modules_are_not_available(): void
    {
        foreach (config('hris_setup') as $master => $config) {
            $response = $this->get(route('master-data.index', $master))->assertOk();
            foreach ($config['fields'] as $meta) {
                $response->assertSee($meta['label']);
            }
        }
        $this->get('/master-data/attendance')->assertNotFound();
        $this->get('/master-data/reports')->assertNotFound();
    }

    public function test_removed_fields_are_not_stored_and_reference_fields_are_validated(): void
    {
        $this->post('/master-data/grades', ['code' => 'NEW', 'name' => 'New Grade', 'rank' => 2, 'status' => 'active', 'salary_from' => 100, 'salary_to' => 50, 'award_amount' => 25])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('grades', ['code' => 'NEW', 'rank' => 2]);
        foreach (['grades', 'designations'] as $table) {
            foreach (['salary_from', 'salary_to', 'increment_by', 'increment_value', 'percentage_of'] as $field) {
                $this->assertFalse(Schema::hasColumn($table, $field));
                $this->assertArrayNotHasKey($field, config('hris_setup.'.$table.'.fields'));
            }
        }
        $this->assertFalse(Schema::hasColumn('grades', 'award_amount'));
        $this->assertFalse(Schema::hasColumn('designations', 'domain'));
        $this->post('/master-data/certifications', ['name' => 'New Certification', 'status' => 'active'])->assertSessionHasErrors('certification_type_id');
        $this->post('/master-data/fiscal_years', ['name' => 'Invalid Year', 'starts_on' => '2026-12-31', 'ends_on' => '2026-01-01', 'status' => 'active'])->assertSessionHasErrors('ends_on');
    }

    public function test_news_company_selection_is_saved_and_replaced(): void
    {
        $companies = [auth()->user()->employee->company_id];
        $payload = ['title' => 'Setup announcement', 'body' => 'Details', 'published_on' => '2026-10-09', 'company_ids' => $companies];
        $this->post('/master-data/news', $payload)->assertSessionHasNoErrors();
        $id = DB::table('news')->where('title', $payload['title'])->value('id');
        $this->assertSame(count($companies), DB::table('news_companies')->where('news_id', $id)->count());
        $payload['company_ids'] = [$companies[0]];
        $this->put('/master-data/news/'.$id, $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('news_companies')->where('news_id', $id)->count());
    }

    public function test_news_company_visibility_is_respected(): void
    {
        $employeeUser = User::where('role', 'employee')->first();
        $companyId = DB::table('employees')->where('user_id', $employeeUser->id)->value('company_id');
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Company', 'code' => 'OTHER', 'status' => 'active']);
        foreach ([$companyId => 'Visible announcement', $otherCompany => 'Hidden announcement'] as $company => $subject) {
            if ($company === $otherCompany) {
                $id = DB::table('news')->insertGetId(['title' => $subject, 'body' => 'Details', 'published_on' => '2026-10-09', 'status' => 'published']);
                DB::table('news_companies')->insert(['news_id' => $id, 'company_id' => $company]);
            } else {
                $this->post('/master-data/news', ['title' => $subject, 'body' => 'Details', 'published_on' => '2026-10-09', 'company_ids' => [$company]])->assertSessionHasNoErrors();
            }
        }
        $this->actingAs($employeeUser)->get('/')->assertOk()->assertSee('Visible announcement')->assertDontSee('Hidden announcement');
    }

    public function test_hr_document_upload_download_replacement_and_delete(): void
    {
        Storage::fake('local');
        $type = DB::table('document_types')->first();
        DB::table('document_types')->where('id', $type->id)->update(['file_extensions' => 'txt']);
        $payload = ['name' => 'HR Policy', 'document_type_id' => $type->id, 'publish_on_portal' => 1, 'document_file' => UploadedFile::fake()->createWithContent('policy.txt', 'Policy text')];
        $this->post('/master-data/hr_documents', $payload)->assertSessionHasNoErrors();
        $record = DB::table('hr_documents')->where('name', 'HR Policy')->first();
        Storage::disk('local')->assertExists($record->document_file);
        $this->get(route('master-data.download', ['hr_documents', $record->id, 'document_file']))->assertOk();
        unset($payload['document_file']);
        $this->put('/master-data/hr_documents/'.$record->id, $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hr_documents', ['id' => $record->id, 'document_file' => $record->document_file]);
        $payload['document_file'] = UploadedFile::fake()->createWithContent('replacement.txt', 'Replacement');
        $this->put('/master-data/hr_documents/'.$record->id, $payload)->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($record->document_file);
        $newPath = DB::table('hr_documents')->where('id', $record->id)->value('document_file');
        $this->delete('/master-data/document_types/'.$type->id)->assertSessionHasErrors('master');
        $this->actingAs(User::where('role', 'employee')->first())->get(route('master-data.download', ['hr_documents', $record->id, 'document_file']))->assertForbidden();
        $this->actingAs(User::where('role', 'admin')->first())->delete('/master-data/hr_documents/'.$record->id)->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($newPath);
    }

    public function test_legacy_designation_fields_cannot_change_employee_reporting(): void
    {
        $payload = ['code' => 'SETUP', 'name' => 'Setup Role', 'status' => 'active', 'company_id' => DB::table('companies')->value('id'), 'grade_id' => DB::table('grades')->value('id'), 'company_head' => 1];
        foreach (['departments' => 'department_id', 'job_categories' => 'job_category_id'] as $table => $field) {
            $payload[$field] = DB::table($table)->insertGetId(['name' => 'Setup Lookup', 'status' => 'active']);
        }
        $this->post('/master-data/designations', $payload)->assertSessionHasNoErrors();
        $id = DB::table('designations')->where('code', 'SETUP')->value('id');
        $this->assertDatabaseHas('designations', ['id' => $id, 'name' => 'Setup Role']);
        $reporting = DB::table('employees')->orderBy('id')->get(['id', 'primary_report_to_id', 'secondary_report_to_id'])->toJson();
        $payload['report_to_designation_id'] = $id;
        $this->put('/master-data/designations/'.$id, $payload)->assertSessionHasNoErrors();
        $this->assertFalse(Schema::hasColumn('designations', 'report_to_designation_id'));
        $this->assertFalse(Schema::hasColumn('designations', 'company_head'));
        $this->assertSame($reporting, DB::table('employees')->orderBy('id')->get(['id', 'primary_report_to_id', 'secondary_report_to_id'])->toJson());
    }
}
