<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveAttendance\AttendanceVersion;
use App\Models\LeaveAttendance\ImportBatch;
use App\Models\LeaveAttendance\Shift;
use App\Models\LeaveAttendance\ShiftAssignment;
use App\Models\User;
use App\Services\LeaveAttendance\SpreadsheetReader;
use App\Services\LeaveAttendance\ImportService;
use App\Services\LeaveAttendance\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttendanceUploadTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\EmployeeAccountFixtures;

    private array $paths = [];

    private function mappedBatch(array $rows): array
    {
        $company = Company::create(['code' => 'REVIEW', 'name' => 'Editable upload fixture', 'status' => 'active']);
        $hr = $this->linkedHr($company);
        $department = \App\Models\Department::create(['code'=>'STAFF','name'=>'Staff','company_id'=>$company->id,'status'=>'active']);
        $employees = [];
        $shift = Shift::create(['name' => 'Review shift', 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'overnight' => false]);
        foreach (['0001', '0002'] as $code) {
            $employee = Employee::create(['department_id'=>$department->id, 'employee_no' => $code, 'first_name' => 'Review', 'last_name' => $code, 'date_of_joining' => '2026-01-01', 'company_id' => $company->id]);
            ShiftAssignment::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'starts_on' => '2026-01-01', 'actor_id' => $hr->id]);
            $employees[] = $employee;
        }
        $source = [];
        foreach ($rows as $number => $cells) $source[] = ['row' => $number, 'cells' => $cells];
        $batch = ImportBatch::create(['actor_id' => $hr->id, 'filename' => 'review.xlsx', 'fingerprint' => hash('sha256', json_encode($rows)),
            'headers' => ['Code', 'Date', 'Time In', 'Time Out'], 'rows' => $source]);
        $mapping = ['employee_no' => 0, 'shift_date' => 1, 'time_in' => 2, 'time_out' => 3];
        $batch->update(['mapping' => $mapping, 'results' => app(ImportService::class)->preview($batch, $mapping, $hr), 'status' => 'mapped']);
        $this->actingAs($hr);

        return [$hr, $employees[0], $employees[1], $batch];
    }

    private function review(ImportBatch $batch, array $changes, ?string $revision = null)
    {
        return $this->post(route('la.import.review', $batch), ['changes' => json_encode($changes), 'revision' => $revision ?? app(ImportService::class)->revision($batch->fresh())]);
    }

    public function test_editable_preview_corrects_rows_excludes_rows_and_saves_only_final_verified_values(): void
    {
        [$hr, $employee, $other, $batch] = $this->mappedBatch([
            2 => ['0001', '2026-10-29', '2026-10-29 09:00:07', '2026-10-29 17:00:09'],
            3 => ['UNKNOWN', '2026-10-30', '2026-10-30 09:00:00', '2026-10-30 17:00:00'],
            4 => ['0002', '2026-10-29', '2026-10-29 09:00:00', '2026-10-29 17:00:00'],
        ]);
        $this->review($batch, [
            ['row' => 2, 'time_out_date' => '2026-10-29', 'time_out_hour' => '05', 'time_out_minute' => '15', 'time_out_period' => 'PM'],
            ['row' => 3, 'employee_no' => '0001'], ['row' => 4, 'excluded' => true],
        ])->assertSessionHasNoErrors();
        $rows = collect($batch->fresh()->results)->keyBy('row');
        $this->assertSame('2026-10-29 09:00:07', $rows[2]['data']['time_in']);
        $this->assertSame('2026-10-29 17:15:00', $rows[2]['data']['time_out']);
        $this->assertSame('2026-10-29 17:00:09', $rows[2]['source_data']['time_out']);
        $this->assertSame([], $rows[3]['errors']);
        $this->assertTrue($rows[4]['excluded']);
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
        $revision = app(ImportService::class)->revision($batch->fresh());
        $this->post(route('la.import.commit', $batch), ['revision' => $revision])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hrla_attendance_versions', 2);
        $this->assertDatabaseHas('hrla_attendance_versions', ['employee_id' => $employee->id, 'source_row' => 2, 'batch_id' => $batch->id, 'time_in' => '2026-10-29 09:00:07', 'time_out' => '2026-10-29 17:15:00']);
        $this->assertSame(1, $batch->fresh()->results['excluded']);
        $this->assertSame('excluded', $batch->fresh()->results['rows'][2]['outcome']);
        $this->post(route('la.import.commit', $batch), ['revision' => $revision])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hrla_attendance_versions', 2);
        $this->get(route('la.import.preview', $batch))->assertOk()->assertSee('Imported 2')->assertDontSee('data-import-review-form', false);
        $this->review($batch, [['row' => 2, 'excluded' => true]])->assertSessionHasErrors('review');
    }

    public function test_edit_preview_revision_protects_against_another_tab_and_stale_commit(): void
    {
        [, , , $batch] = $this->mappedBatch([2 => ['0001', '2026-10-29', '2026-10-29 09:00:00', '2026-10-29 17:00:00']]);
        $old = app(ImportService::class)->revision($batch);
        $this->review($batch, [['row' => 2, 'excluded' => true]], $old)->assertSessionHasNoErrors();
        $this->review($batch, [['row' => 2, 'excluded' => false]], $old)->assertSessionHasErrors('revision');
        $this->post(route('la.import.commit', $batch), ['revision' => $old])->assertSessionHasErrors('revision');
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
    }

    public function test_revalidation_does_not_silently_replace_a_changed_attendance_baseline(): void
    {
        [$hr, $employee, , $batch] = $this->mappedBatch([2 => ['0001', '2026-10-29', '2026-10-29 09:00:00', '2026-10-29 17:00:00']]);
        $manual = app(AttendanceService::class)->append($employee, $hr, ['shift_date' => '2026-10-29', 'time_in' => '2026-10-29 09:05:00', 'time_out' => '2026-10-29 17:00:00', 'reason_type' => 'correction', 'reason' => 'Newer manual entry'], 'manual', null);
        $this->review($batch, [['row' => 2, 'employee_no' => '0001']])->assertSessionHasNoErrors();
        $row = $batch->fresh()->results[0];
        $this->assertNull($row['base_version_id']);
        $this->assertStringContainsString('Attendance changed', implode(' ', $row['errors']));
        $this->review($batch, [['row' => 2, 'refresh_current' => true]])->assertSessionHasNoErrors();
        $row = $batch->fresh()->results[0];
        $this->assertSame($manual->id, $row['base_version_id']);
        $this->assertSame('2026-10-29 09:05:00', $row['current']['time_in']);
        $this->assertSame([], $row['errors']);
        $this->post(route('la.import.commit', $batch), ['revision' => app(ImportService::class)->revision($batch->fresh())])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hrla_attendance_versions', 2);
        $this->assertSame('2026-10-29 09:05:00', $manual->fresh()->time_in);
    }

    public function test_excluding_first_duplicate_allows_the_second_row_to_be_verified(): void
    {
        [, , , $batch] = $this->mappedBatch([2 => ['0001', '2026-10-29', '2026-10-29 09:00:00', '2026-10-29 17:00:00'], 3 => ['0001', '2026-10-29', '2026-10-29 09:10:00', '2026-10-29 17:00:00']]);
        $this->assertNotEmpty($batch->results[1]['errors']);
        $this->review($batch, [['row' => 2, 'excluded' => true]])->assertSessionHasNoErrors();
        $this->assertSame([], $batch->fresh()->results[1]['errors']);
        $this->post(route('la.import.commit', $batch))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hrla_attendance_versions', ['source_row' => 3]);
        $this->assertDatabaseCount('hrla_attendance_versions', 1);
    }

    public function test_review_rejects_foreign_batch_rows_invalid_json_and_unauthorized_actors(): void
    {
        [$hr, , , $batch] = $this->mappedBatch([2 => ['0001', '2026-10-29', '', '']]);
        $otherHr = $this->linkedHr();
        $this->actingAs($otherHr);
        $this->review($batch, [['row' => 2, 'excluded' => true]])->assertForbidden();
        $this->actingAs($hr);
        $this->review($batch, [['row' => 999, 'excluded' => true]])->assertSessionHasErrors('review');
        $this->post(route('la.import.review', $batch), ['changes' => 'invalid', 'revision' => app(ImportService::class)->revision($batch)])->assertSessionHasErrors('edits');
        $this->review($batch, [['row' => 2], ['row' => 2]])->assertSessionHasErrors('edits.0.row');
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
    }

    public function test_overnight_clock_edits_keep_actual_dates_and_untouched_seconds(): void
    {
        [$hr, $employee, , $batch] = $this->mappedBatch([2 => ['0001', '2026-10-29', '2026-10-29 22:00:07', '2026-10-30 06:00:00']]);
        $night = Shift::create(['name' => 'Night review', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'overnight' => true]);
        ShiftAssignment::where('employee_id', $employee->id)->update(['shift_id' => $night->id]);
        $batch->update(['results' => app(ImportService::class)->preview($batch, $batch->mapping, $hr)]);
        $this->review($batch, [['row' => 2, 'time_in_date' => '2026-10-29', 'time_in_hour' => '10', 'time_in_minute' => '00', 'time_in_period' => 'PM',
            'time_out_date' => '2026-10-30', 'time_out_hour' => '06', 'time_out_minute' => '05', 'time_out_period' => 'AM']])->assertSessionHasNoErrors();
        $row = $batch->fresh()->results[0];
        $this->assertSame('2026-10-29 22:00:07', $row['data']['time_in']);
        $this->assertSame('2026-10-30 06:05:00', $row['data']['time_out']);
        $this->assertSame(29093, $row['calculation']['work_seconds']);
    }

    public function test_invalid_original_punch_is_retained_until_explicitly_changed_or_cleared(): void
    {
        [, , , $batch] = $this->mappedBatch([2 => ['0001', '2026-10-29', 'bad clock', '2026-10-29 17:00:00']]);
        $this->review($batch, [['row' => 2, 'employee_no' => '0001']])->assertSessionHasNoErrors();
        $this->assertSame('bad clock', $batch->fresh()->results[0]['data']['time_in']);
        $this->assertNotEmpty($batch->fresh()->results[0]['errors']);
        $this->review($batch, [['row' => 2, 'time_in_date' => '2026-10-29', 'time_in_hour' => '', 'time_in_minute' => '', 'time_in_period' => 'AM']])->assertSessionHasNoErrors();
        $row = $batch->fresh()->results[0];
        $this->assertNull($row['data']['time_in']);
        $this->assertSame([], $row['errors']);
        $this->assertSame('Missing Time In', $row['calculation']['status']);
    }

    public function test_time_only_spreadsheet_punch_is_automatically_dated_and_preserves_seconds(): void
    {
        [, , , $batch] = $this->mappedBatch([2 => ['0001', '2026-10-29', '12:00:07', '2026-10-29 17:00:00']]);
        $this->assertSame([], $batch->results[0]['errors']);
        $this->assertSame('2026-10-29 12:00:07', $batch->results[0]['data']['time_in']);
        $html = $this->get(route('la.import.preview', $batch))->assertOk()->assertSee('Automatically fixed:')->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame('2026-10-29', $xpath->evaluate('string(//*[@data-import-punch="time_in"]//input[@data-punch-date]/@value)'));
        $this->assertSame('12', $xpath->evaluate('string(//*[@data-import-punch="time_in"]//input[@data-clock-hour]/@value)'));
        $this->review($batch, [['row' => 2, 'time_in_date' => '2026-10-29', 'time_in_hour' => '12', 'time_in_minute' => '00', 'time_in_period' => 'PM']])->assertSessionHasNoErrors();
        $this->assertSame([], $batch->fresh()->results[0]['errors']);
        $this->assertSame('2026-10-29 12:00:07', $batch->fresh()->results[0]['data']['time_in']);
        $this->assertSame('12:00:07', $batch->fresh()->results[0]['source_data']['time_in']);
    }

    public function test_spreadsheet_cell_edits_replace_stale_clock_parts_and_save_exact_verified_values(): void
    {
        [, $employee, , $batch] = $this->mappedBatch([2 => ['0001', '2026-10-29', '2026-10-29 09:00:07', '2026-10-29 17:00:00']]);
        $this->review($batch, [['row' => 2, 'time_in_date' => '2026-10-29', 'time_in_hour' => '00', 'time_in_minute' => '10', 'time_in_period' => 'AM']])->assertSessionHasNoErrors();
        $this->assertNotEmpty($batch->fresh()->results[0]['errors']);
        $this->assertArrayHasKey('time_in', $batch->fresh()->results[0]['edit_parts']);
        $this->review($batch, [['row' => 2, 'time_in' => '2026-10-29 09:20:11', 'time_out' => '2026-10-29 17:15:13']])->assertSessionHasNoErrors();
        $result = $batch->fresh()->results[0];
        $this->assertSame([], $result['errors']);
        $this->assertArrayNotHasKey('time_in', $result['edit_parts']);
        $this->assertSame('2026-10-29 09:00:07', $result['source_data']['time_in']);
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
        $this->post(route('la.import.commit', $batch), ['revision' => app(ImportService::class)->revision($batch->fresh())])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hrla_attendance_versions', ['employee_id' => $employee->id, 'shift_date' => '2026-10-29', 'time_in' => '2026-10-29 09:20:11', 'time_out' => '2026-10-29 17:15:13']);
    }

    private function workbook(array $rows, bool $formula = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hrla-test-');
        $this->paths[] = $path;
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Attendance" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $number => $row) {
            $xml .= '<row r="'.$number.'">';
            foreach ($row as $column => $value) {
                $xml .= '<c r="'.chr(65 + $column).$number.'" t="inlineStr">'.($formula && $number > 1 ? '<f>HYPERLINK("https://example.invalid")</f>' : '').'<is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
        $zip->close();

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_xlsx_reader_retains_actual_row_numbers_and_string_codes(): void
    {
        $path = $this->workbook([1 => ['Code', 'Date', 'Time In', 'Time Out'], 4 => ['0001', '2026-10-29', '2026-10-29 09:00:00', ''], 7 => ['0002', '2026-10-29', '', '2026-10-29 17:00:00']]);
        [$headers, $rows] = app(SpreadsheetReader::class)->read($path, 'xlsx');
        $this->assertSame(['Code', 'Date', 'Time In', 'Time Out'], $headers);
        $this->assertSame(4, $rows[0]['row']);
        $this->assertSame(7, $rows[1]['row']);
        $this->assertSame('0001', $rows[0]['cells'][0]);
    }

    public function test_formula_cells_are_rejected_without_execution(): void
    {
        $path = $this->workbook([1 => ['Code', 'Date', 'Time In', 'Time Out'], 2 => ['WORKER', '2026-10-29', '', '']], true);
        $this->expectException(ValidationException::class);
        app(SpreadsheetReader::class)->read($path, 'xlsx');
    }

    public function test_upload_mapping_preview_commit_and_duplicate_upload_work_end_to_end(): void
    {
        $company = Company::create(['code' => 'IMPORT', 'name' => 'Upload fixture', 'status' => 'active']);
        $hr = $this->linkedHr($company);
        $department = \App\Models\Department::create(['code'=>'STAFF','name'=>'Staff','company_id'=>$company->id,'status'=>'active']);
        $employee = Employee::create(['department_id'=>$department->id, 'employee_no' => '0001', 'first_name' => 'Upload', 'last_name' => 'Fixture', 'date_of_joining' => '2026-01-01', 'company_id' => $company->id]);
        $shift = Shift::create(['name' => 'Upload schedule', 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'overnight' => false]);
        ShiftAssignment::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'starts_on' => '2026-01-01', 'actor_id' => $hr->id]);
        $path = $this->workbook([1 => ['Code', 'Date', 'Time In', 'Time Out'], 2 => ['0001', '2026-10-29', '2026-10-29 09:15:00', '2026-10-29 17:00:00'], 3 => ['UNKNOWN', '2026-10-29', '', '']]);
        $upload = fn () => new UploadedFile($path, 'actual.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->actingAs($hr)->post('/leave-attendance/import', ['file' => $upload()])->assertSessionHasNoErrors()->assertRedirect();
        $batch = ImportBatch::firstOrFail();
        $this->get('/leave-attendance/import/'.$batch->id)->assertOk()->assertSee('Code');
        $this->post('/leave-attendance/import/'.$batch->id.'/mapping', ['mapping' => ['employee_no' => 0, 'shift_date' => 1, 'time_in' => 2, 'time_out' => 3]])->assertSessionHasNoErrors();
        $this->get('/leave-attendance/import/'.$batch->id)->assertOk()->assertSee('Unknown or unauthorized employee');
        $this->post('/leave-attendance/import/'.$batch->id.'/commit')->assertSessionHasNoErrors();
        $this->assertSame(1, AttendanceVersion::count());
        $this->get('/leave-attendance/import/'.$batch->id)->assertOk()->assertSee('Imported 1');
        $this->post('/leave-attendance/import', ['file' => $upload()])->assertRedirect('/leave-attendance/import/'.$batch->id);
        $this->post('/leave-attendance/import/'.$batch->id.'/commit')->assertSessionHasNoErrors();
        $this->assertSame(1, AttendanceVersion::count());
        $this->assertSame(1, ImportBatch::count());
        $this->get('/leave-attendance/import/template')->assertOk()->assertDownload('attendance-template.xlsx');
        $outsider = $this->linkedHr();
        $this->actingAs($outsider)->get('/leave-attendance/import/'.$batch->id)->assertForbidden();
        $this->post('/leave-attendance/import/'.$batch->id.'/commit')->assertForbidden();
    }

    public function test_malformed_upload_and_duplicate_mapping_are_rejected(): void
    {
        $hr = $this->linkedHr();
        $this->actingAs($hr)->post('/leave-attendance/import', ['file' => UploadedFile::fake()->createWithContent('bad.xlsx', 'not a workbook')])->assertSessionHasErrors('file');
        $batch = ImportBatch::create(['actor_id' => $hr->id, 'filename' => 'test.xlsx', 'fingerprint' => str_repeat('c', 64), 'headers' => ['Code', 'Date', 'Time In', 'Time Out'], 'rows' => [['row' => 2, 'cells' => []]]]);
        $this->post('/leave-attendance/import/'.$batch->id.'/mapping', ['mapping' => ['employee_no' => 0, 'shift_date' => 0, 'time_in' => 2, 'time_out' => 3]])->assertSessionHasErrors('mapping');
        $this->post('/leave-attendance/import/'.$batch->id.'/commit')->assertSessionHasErrors('file');
        $this->assertSame(0, AttendanceVersion::count());
    }
}
