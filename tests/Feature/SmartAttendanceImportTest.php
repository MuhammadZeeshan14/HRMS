<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveAttendance\ImportBatch;
use App\Models\LeaveAttendance\Shift;
use App\Models\LeaveAttendance\ShiftAssignment;
use App\Models\User;
use App\Services\LeaveAttendance\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SmartAttendanceImportTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\EmployeeAccountFixtures;

    private function staff(): array
    {
        $company = Company::create(['code' => 'SMART', 'name' => 'Smart import', 'status' => 'active']);
        $hr = $this->linkedHr($company);
        $department = \App\Models\Department::create(['code'=>'STAFF','name'=>'Staff','company_id'=>$company->id,'status'=>'active']);
        $employees = [];
        foreach ([['0001', 'Jane', 'Doe', '09:00:00', '17:00:00', false], ['0002', 'Nadir', 'Khan', '22:00:00', '06:00:00', true]] as [$code, $first, $last, $start, $end, $overnight]) {
            $employee = Employee::create(['department_id'=>$department->id, 'employee_no' => $code, 'first_name' => $first, 'last_name' => $last, 'company_id' => $company->id, 'date_of_joining' => '2026-01-01']);
            $shift = Shift::create(['name' => $overnight ? 'Night' : 'Day', 'start_time' => $start, 'end_time' => $end, 'overnight' => $overnight]);
            ShiftAssignment::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'starts_on' => '2026-01-01', 'actor_id' => $hr->id]);
            $employees[] = $employee;
        }
        $this->actingAs($hr);
        return [$hr, ...$employees];
    }

    private function upload(string $content): ImportBatch
    {
        $this->post(route('la.import.upload'), ['file' => UploadedFile::fake()->createWithContent('report.csv', $content)])->assertSessionHasNoErrors();
        return ImportBatch::latest('id')->firstOrFail();
    }

    private function map(ImportBatch $batch, array $options = [], ?array $columns = null): ImportBatch
    {
        $columns ??= array_intersect_key($batch->mapping, array_flip(['employee_no', 'shift_date', 'time_in', 'time_out', 'timestamp', 'direction']));
        $columns = array_filter($columns, fn ($value) => $value !== null);
        $this->post(route('la.import.map', $batch), ['mapping' => $columns, 'revision' => app(ImportService::class)->revision($batch), ...($batch->mapping['_options'] ?? []), ...$options])->assertSessionHasNoErrors();
        return $batch->fresh();
    }

    public function test_report_titles_reordered_columns_repeated_headers_names_and_clock_values(): void
    {
        [, $employee] = $this->staff();
        $batch = $this->upload("Monthly attendance report\nCompany: Office\nOut Time;EmployeeName;Attendance Date;InTime\n5:00 PM;Jane Doe;31/10/2026;9:00 AM\nOut Time;EmployeeName;Attendance Date;InTime\n17:00;Jane Doe;03/11/2026;09:05:07\n");
        $this->assertSame(3, $batch->mapping['_source']['header_row']);
        $this->assertSame([5], $batch->mapping['_source']['repeated_headers']);
        $this->assertSame(1, $batch->mapping['employee_no']);
        $this->get(route('la.import.preview', $batch))->assertOk()->assertSee('Detected header row')->assertSee('Spreadsheet sample')->assertDontSee('data-import-review-form', false);
        $batch = $this->map($batch);
        $this->assertCount(2, $batch->results);
        $this->assertSame([], $batch->results[0]['errors']);
        $this->assertSame('0001', $batch->results[0]['data']['employee_no']);
        $this->assertSame('2026-11-03 09:05:07', $batch->results[1]['data']['time_in']);
        $this->assertSame('Jane Doe', $batch->results[0]['source_data']['employee_no']);
        $this->assertNotEmpty($batch->results[0]['interpretation_notes']);
        $this->post(route('la.import.commit', $batch), ['revision' => app(ImportService::class)->revision($batch)])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hrla_attendance_versions', ['employee_id' => $employee->id, 'shift_date' => '2026-10-31', 'time_out' => '2026-10-31 17:00:00', 'source_row' => 4]);
    }

    public function test_ambiguous_dates_remain_invalid_until_hr_selects_date_order(): void
    {
        $this->staff();
        $batch = $this->map($this->upload("Code,Date,In,Out\n0001,03/04/2026,09:00,17:00\n"));
        $this->assertNotEmpty($batch->results[0]['errors']);
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
        $this->assertSame('auto', \App\Services\LeaveAttendance\ImportFormats::order(['32/10/2026', '03/04/2026']));
        $batch = $this->map($batch, ['date_order' => 'dmy']);
        $this->assertSame('2026-04-03', $batch->results[0]['data']['shift_date']);
        $this->assertSame([], $batch->results[0]['errors']);
        $batch = $this->map($batch, ['date_order' => 'mdy']);
        $this->assertSame('2026-03-04', $batch->results[0]['data']['shift_date']);
    }

    public function test_clock_only_overnight_dates_follow_the_assigned_shift_and_retain_seconds(): void
    {
        $this->staff();
        $batch = $this->map($this->upload("Staff ID,Work Date,Arrival,Departure\n2,2026-10-30,10:00 PM,6:05:07 AM\n"));
        $this->assertSame([], $batch->results[0]['errors']);
        $this->assertSame('0002', $batch->results[0]['data']['employee_no']);
        $this->assertSame('2026-10-30 22:00:00', $batch->results[0]['data']['time_in']);
        $this->assertSame('2026-10-31 06:05:07', $batch->results[0]['data']['time_out']);
        $this->assertSame('6:05:07 AM', $batch->results[0]['source_data']['time_out']);
    }

    public function test_unsorted_punch_logs_group_overnight_rows_with_original_provenance(): void
    {
        [, , $night] = $this->staff();
        $batch = $this->upload("Badge ID,Punch Time,Punch Type\n0002,2026-10-31 06:15:07,OUT\n0002,2026-10-30 22:05:00,IN\n0002,2026-10-30 22:00:01,IN\n0002,2026-10-31 06:00:00,OUT\n");
        $this->assertSame('punches', $batch->mapping['_options']['layout']);
        $batch = $this->map($batch);
        $this->assertCount(1, $batch->results);
        $this->assertSame([], $batch->results[0]['errors']);
        $this->assertSame([2, 3, 4, 5], $batch->results[0]['source_rows']);
        $this->assertSame('2026-10-30', $batch->results[0]['data']['shift_date']);
        $this->assertSame('2026-10-30 22:00:01', $batch->results[0]['data']['time_in']);
        $this->assertSame('2026-10-31 06:15:07', $batch->results[0]['data']['time_out']);
        $this->post(route('la.import.review', $batch), ['changes' => json_encode([['row' => 2, 'time_out_date' => '2026-10-31', 'time_out_hour' => '06', 'time_out_minute' => '20', 'time_out_period' => 'AM']]), 'revision' => app(ImportService::class)->revision($batch)])->assertSessionHasNoErrors();
        $batch = $batch->fresh();
        $this->assertSame('2026-10-31 06:15:07', $batch->results[0]['source_data']['time_out']);
        $this->post(route('la.import.commit', $batch), ['revision' => app(ImportService::class)->revision($batch)])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hrla_attendance_versions', 1);
        $this->assertDatabaseHas('hrla_attendance_versions', ['employee_id' => $night->id, 'shift_date' => '2026-10-30', 'source_row' => 2, 'time_out' => '2026-10-31 06:20:00', 'source' => 'spreadsheet import']);
        $this->assertSame([2, 3, 4, 5], $batch->fresh()->results['rows'][0]['source_rows']);
    }

    public function test_unknown_device_direction_and_duplicate_employee_names_are_not_guessed(): void
    {
        [, , $other] = $this->staff();
        $other->update(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $batch = $this->map($this->upload("Employee Name,Date,In,Out\nJane Doe,2026-10-30,09:00,17:00\n"));
        $this->assertNotEmpty($batch->results[0]['errors']);
        $batch = $this->map($this->upload("Code,Timestamp,Direction\n0001,2026-10-30 09:00,0\n"));
        $this->assertNotEmpty($batch->results[0]['errors']);
        $this->assertStringContainsString('Unrecognized', $batch->results[0]['data']['time_in']);
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
    }

    public function test_missing_checkout_and_date_column_can_be_derived_from_full_checkin(): void
    {
        $this->staff();
        $batch = $this->map($this->upload("Code,CheckIn\n0001,30 Oct 2026 9:10 AM\n"));
        $this->assertSame([], $batch->results[0]['errors']);
        $this->assertSame('2026-10-30', $batch->results[0]['data']['shift_date']);
        $this->assertSame('2026-10-30 09:10:00', $batch->results[0]['data']['time_in']);
        $this->assertNull($batch->results[0]['data']['time_out']);
        $batch = $this->map($this->upload("Code,CheckOut\n0002,2026-10-31 6:15 AM\n"));
        $this->assertSame([], $batch->results[0]['errors']);
        $this->assertSame('2026-10-30', $batch->results[0]['data']['shift_date']);
        $this->assertSame('2026-10-31 06:15:00', $batch->results[0]['data']['time_out']);
    }

    public function test_header_override_is_revision_protected_and_retains_original_source(): void
    {
        $this->staff();
        $batch = $this->upload("Title\nCode,Date,In,Out\n0001,2026-10-30,09:00,17:00\n");
        $revision = app(ImportService::class)->revision($batch);
        $this->post(route('la.import.layout', $batch), ['header_row' => 1, 'revision' => $revision])->assertSessionHasNoErrors();
        $this->assertSame(1, $batch->fresh()->mapping['_source']['header_row']);
        $this->post(route('la.import.layout', $batch), ['header_row' => 2, 'revision' => $revision])->assertSessionHasErrors('header_row');
        $this->post(route('la.import.layout', $batch), ['header_row' => 2, 'revision' => app(ImportService::class)->revision($batch->fresh())])->assertSessionHasNoErrors();
        $this->assertSame('Code', $batch->fresh()->headers[0]);
        $this->assertCount(3, $batch->fresh()->mapping['_source']['rows']);
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
    }

    public function test_common_format_errors_are_repaired_during_mapping(): void
    {
        $this->staff();
        $batch = $this->map($this->upload("Code,Date,In,Out\n0001,\"Monday, 2 November, 2026\",\" 9 : 4 a.m. \",1730\n"));
        $row = $batch->results[0];
        $this->assertSame([], $row['errors']);
        $this->assertSame('2026-11-02', $row['data']['shift_date']);
        $this->assertSame('2026-11-02 09:04:00', $row['data']['time_in']);
        $this->assertSame('2026-11-02 17:30:00', $row['data']['time_out']);
        $this->assertContains('shift_date', $row['auto_format_fields']);
        $this->assertSame('Monday, 2 November, 2026', $row['source_data']['shift_date']);
    }

    public function test_fix_formats_revalidates_existing_preview_without_resetting_manual_edits(): void
    {
        [, $employee] = $this->staff();
        $batch = $this->map($this->upload("Code,Date,In,Out\n0001,2026-11-02,09:00,17:00\n"));
        $rows = $batch->results;
        $source = $rows[0]['source_data'];
        $rows[0]['data'] = ['employee_no' => '0001', 'shift_date' => '11/02/2026', 'time_in' => '9:46 a.m.', 'time_out' => '18:10'];
        $rows[0]['errors'] = ['Old formatting errors'];
        $batch->update(['results' => $rows]);
        $revision = app(ImportService::class)->revision($batch);
        $request = ['changes' => '[]', 'auto_fix' => '1', 'date_order' => 'mdy', 'revision' => $revision];
        $this->post(route('la.import.review', $batch), $request)->assertSessionHasNoErrors();
        $batch = $batch->fresh();
        $this->assertSame([], $batch->results[0]['errors']);
        $this->assertSame('2026-11-02 09:46:00', $batch->results[0]['data']['time_in']);
        $this->assertSame('2026-11-02 18:10:00', $batch->results[0]['data']['time_out']);
        $this->assertSame($source, $batch->results[0]['source_data']);
        $this->assertSame('mdy', $batch->mapping['_options']['date_order']);
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
        $this->post(route('la.import.review', $batch), $request)->assertSessionHasErrors('revision');
        $this->post(route('la.import.commit', $batch), ['revision' => app(ImportService::class)->revision($batch)])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hrla_attendance_versions', ['employee_id' => $employee->id, 'time_in' => '2026-11-02 09:46:00', 'time_out' => '2026-11-02 18:10:00']);
    }

    public function test_excel_serial_dates_and_fractional_clocks_follow_workbook_calendar(): void
    {
        $this->staff();
        $serial = \PhpOffice\PhpSpreadsheet\Shared\Date::stringToExcel('2026-11-02', 1904);
        $batch = $this->upload("Code,Date,In,Out\n0001,{$serial},0.375,0.75\n");
        $mapping = $batch->mapping;
        $mapping['_source']['excel_calendar'] = 1904;
        $batch->update(['mapping' => $mapping]);
        $batch = $this->map($batch);
        $this->assertSame([], $batch->results[0]['errors']);
        $this->assertSame('2026-11-02', $batch->results[0]['data']['shift_date']);
        $this->assertSame('2026-11-02 09:00:00', $batch->results[0]['data']['time_in']);
        $this->assertSame('2026-11-02 18:00:00', $batch->results[0]['data']['time_out']);
    }

    public function test_matching_full_timestamp_resolves_date_order_and_preserves_seconds(): void
    {
        $this->staff();
        $batch = $this->map($this->upload("Code,Date,In,Out\n0001,11/2/2026,2026/11/2 9:46:07.000 AM,2026/11/2 6:10:13 PM\n"));
        $this->assertSame([], $batch->results[0]['errors']);
        $this->assertSame('2026-11-02', $batch->results[0]['data']['shift_date']);
        $this->assertSame('2026-11-02 09:46:07', $batch->results[0]['data']['time_in']);
        $this->assertSame('2026-11-02 18:10:13', $batch->results[0]['data']['time_out']);
    }

    public function test_auto_fix_does_not_guess_invalid_or_ambiguous_dates(): void
    {
        $this->staff();
        $batch = $this->map($this->upload("Code,Date,In,Out\n0001,03/04/2026,09:00,17:00\n0002,31/02/2026,22:00,06:00\n"));
        $this->post(route('la.import.review', $batch), ['changes' => '[]', 'auto_fix' => 1, 'revision' => app(ImportService::class)->revision($batch)])->assertSessionHasNoErrors();
        $rows = $batch->fresh()->results;
        $this->assertNotEmpty($rows[0]['errors']);
        $this->assertNotEmpty($rows[1]['errors']);
        $this->assertCount(1, $rows[0]['errors']);
        $this->assertSame('09:00:00', $rows[0]['data']['time_in']);
        $this->assertSame('17:00:00', $rows[0]['data']['time_out']);
        $this->assertSame('03/04/2026', $rows[0]['data']['shift_date']);
        $this->assertSame('31/02/2026', $rows[1]['data']['shift_date']);
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
    }

    public function test_unknown_headers_can_be_suggested_from_unique_employee_and_date_values(): void
    {
        $this->staff();
        $batch = $this->upload("PersonRef,CalendarValue,Arrival,Departure\n0001,2026-10-30,09:00,17:00\n0002,2026-10-31,22:00,06:00\n");
        $this->assertSame(0, $batch->mapping['employee_no']);
        $this->assertSame(1, $batch->mapping['shift_date']);
        $batch = $this->map($batch);
        $this->assertSame([], $batch->results[0]['errors']);
        $this->assertSame([], $batch->results[1]['errors']);
    }
}
