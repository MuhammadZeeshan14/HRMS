<?php

namespace Tests\Feature;

use App\Models\LeaveAttendance\ImportBatch;
use App\Models\User;
use App\Services\LeaveAttendance\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpreadsheetUploadTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\EmployeeAccountFixtures;

    private array $paths = [];

    private function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hrla-formats-');
        $this->paths[] = $path;
        return $path;
    }

    private function workbook(string $format, bool $formula = false, int $calendar = 1900): string
    {
        $book = new Spreadsheet;
        $book->setExcelCalendar($calendar);
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Attendance');
        $sheet->fromArray(['Code', 'Date', 'Time In', 'Time Out'], null, 'A1');
        $sheet->setCellValue('A4', 1);
        $sheet->getStyle('A4')->getNumberFormat()->setFormatCode('0000');
        $sheet->setCellValue('B4', Date::stringToExcel('2026-10-30', $calendar));
        $sheet->getStyle('B4')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->setCellValue('C4', Date::stringToExcel('2026-10-30 22:00:07', $calendar));
        $sheet->setCellValue('D4', Date::stringToExcel('2026-10-31 06:00:09', $calendar));
        $sheet->getStyle('C4:D4')->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm:ss');
        if ($formula) $sheet->setCellValue('A4', '=1+1');
        $book->createSheet()->setTitle('Ignored')->setCellValue('A1', '=1+1');
        $path = $this->path();
        $writer = IOFactory::createWriter($book, $format);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $book->disconnectWorksheets();
        return $path;
    }

    public static function formats(): array
    {
        return [
            ['xlsx', 'Xlsx'], ['xls', 'Xls'], ['ods', 'Ods'], ['xlsm', 'Xlsx'],
            ['xltx', 'Xlsx'], ['xltm', 'Xlsx'], ['xlt', 'Xls'], ['ots', 'Ods'],
        ];
    }

    #[DataProvider('formats')]
    public function test_workbook_formats_upload_native_dates_codes_and_first_sheet_only(string $extension, string $format): void
    {
        $hr = $this->linkedHr();
        $path = $this->workbook($format);
        $this->actingAs($hr)->post(route('la.import.upload'), ['file' => new UploadedFile($path, 'attendance.'.$extension, null, null, true)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $batch = ImportBatch::firstOrFail();
        $this->assertSame(['Code', 'Date', 'Time In', 'Time Out'], $batch->headers);
        $this->assertSame([['row' => 4, 'cells' => ['0001', '2026-10-30', '2026-10-30 22:00:07', '2026-10-31 06:00:09']]], $batch->rows);
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
        $this->get(route('la.import.preview', $batch))->assertOk();
    }

    public static function delimited(): array
    {
        return [['csv', ',', 'UTF-8'], ['csv', ';', 'UTF-8'], ['csv', '|', 'UTF-8'], ['tsv', "\t", 'UTF-8'], ['csv', ',', 'UTF-16LE']];
    }

    #[DataProvider('delimited')]
    public function test_delimited_upload_preserves_codes_quoted_cells_blanks_and_unicode(string $extension, string $delimiter, string $encoding): void
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['Code', 'Date', 'Time In', 'Time Out', 'Note'], $delimiter, '"', '');
        fputcsv($stream, ['0001', '2026-10-30', '', '2026-10-31 6:00 AM', 'Office, کراچی; notes | "quoted"'], $delimiter, '"', '');
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        $content = $encoding === 'UTF-16LE' ? "\xFF\xFE".mb_convert_encoding($content, $encoding, 'UTF-8') : "\xEF\xBB\xBF".$content;
        $hr = $this->linkedHr();
        $this->actingAs($hr)->post(route('la.import.upload'), ['file' => UploadedFile::fake()->createWithContent('attendance.'.$extension, $content)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $batch = ImportBatch::firstOrFail();
        $this->assertSame('Code', $batch->headers[0]);
        $this->assertSame('0001', $batch->rows[0]['cells'][0]);
        $this->assertSame('', $batch->rows[0]['cells'][2]);
        $this->assertSame('Office, کراچی; notes | "quoted"', $batch->rows[0]['cells'][4]);
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
    }

    public function test_1904_calendar_and_time_only_cells_are_not_misinterpreted(): void
    {
        [$headers, $rows] = app(SpreadsheetReader::class)->read($this->workbook('Xlsx', false, 1904), 'xlsx');
        $this->assertSame('2026-10-30', $rows[0]['cells'][1]);
        $this->assertSame('2026-10-30 22:00:07', $rows[0]['cells'][2]);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['Code', 'Date', 'Time In', 'Time Out'], null, 'A1');
        $sheet->setCellValueExplicit('A2', '0001', DataType::TYPE_STRING);
        $sheet->setCellValue('B2', '2026-10-30');
        $sheet->setCellValue('C2', 0.5);
        $sheet->getStyle('C2')->getNumberFormat()->setFormatCode('hh:mm');
        $path = $this->path();
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();
        [, $rows] = app(SpreadsheetReader::class)->read($path, 'xlsx');
        $this->assertSame('12:00:00', $rows[0]['cells'][2]);
        $this->assertSame('2026-10-30 12:00:07', \App\Support\HrlaTime::combine('2026-10-30', '12:00 PM', '12:00:07'));
    }

    #[DataProvider('formats')]
    public function test_formulas_are_rejected_in_every_workbook_format(string $extension, string $format): void
    {
        $hr = $this->linkedHr();
        $this->actingAs($hr)->post(route('la.import.upload'), ['file' => new UploadedFile($this->workbook($format, true), 'formula.'.$extension, null, null, true)])
            ->assertSessionHasErrors('file');
        $this->assertDatabaseCount('hrla_import_batches', 0);
    }

    public function test_invalid_content_unsupported_extensions_and_limits_are_rejected(): void
    {
        $hr = $this->linkedHr();
        $this->actingAs($hr);
        foreach ([['renamed.xls', 'not a workbook'], ['wrong.xlsx', 'damaged workbook'], ['script.csv', "<html>not spreadsheet data</html>"], ['program.exe', 'anything'], ['formula.csv', "Code,Date,In,Out\n=1+1,2026-10-30,,"], ['wide.csv', implode(',', range(1, 41))."\n1,2,3,4"], ['large.csv', "Code,Date,In,Out\n".str_repeat("0001,2026-10-30,,\n", 2001)]] as [$name, $content]) {
            $this->post(route('la.import.upload'), ['file' => UploadedFile::fake()->createWithContent($name, $content)])->assertSessionHasErrors('file');
        }
        $this->assertDatabaseCount('hrla_import_batches', 0);
    }

    public function test_actual_workbook_contents_override_misleading_extensions(): void
    {
        $hr = $this->linkedHr();
        $this->actingAs($hr);
        foreach ([['Xlsx', 'xls'], ['Xls', 'xlsx'], ['Ods', 'csv']] as [$format, $extension]) {
            $this->post(route('la.import.upload'), ['file' => new UploadedFile($this->workbook($format), 'renamed.'.$extension, null, null, true)])->assertSessionHasNoErrors();
            $this->assertSame('0001', ImportBatch::latest('id')->firstOrFail()->rows[0]['cells'][0]);
        }
    }

    public function test_device_html_and_text_exports_named_xls_upload_as_tables(): void
    {
        $hr = $this->linkedHr();
        $this->actingAs($hr);
        $exports = [
            '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN"><html><head><style>td {color:red}</style></head><body><script>throw new Error("never run")</script><table><tr><td colspan="4">Attendance report</td></tr><tr><th>Code</th><th>Date</th><th>In</th><th>Out</th></tr><tr><td>0001</td><td>2026-10-30</td><td>09:00</td><td>17:00<img src="http://127.0.0.1:9/not-fetched"></td></tr></table></body></html>',
            "Code\tDate\tIn\tOut\n0001\t2026-10-30\t09:00\t17:00\n",
            "Code,Date,In,Out\n0001,2026-10-30,09:00,17:00\n",
            '<?xml version="1.0"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Attendance"><Table><Row><Cell><Data ss:Type="String">Code</Data></Cell><Cell><Data ss:Type="String">Date</Data></Cell><Cell><Data ss:Type="String">In</Data></Cell><Cell><Data ss:Type="String">Out</Data></Cell></Row><Row><Cell><Data ss:Type="String">0001</Data></Cell><Cell><Data ss:Type="String">2026-10-30</Data></Cell><Cell><Data ss:Type="String">09:00</Data></Cell><Cell><Data ss:Type="String">17:00</Data></Cell></Row></Table></Worksheet></Workbook>',
        ];
        foreach ($exports as $export) {
            $this->post(route('la.import.upload'), ['file' => UploadedFile::fake()->createWithContent('device.xls', $export)])->assertSessionHasNoErrors()->assertRedirect();
            $batch = ImportBatch::latest('id')->firstOrFail();
            $this->assertSame(['Code', 'Date', 'In', 'Out'], $batch->headers);
            $this->assertSame(['0001', '2026-10-30', '09:00', '17:00'], $batch->rows[0]['cells']);
            $this->get(route('la.import.preview', $batch))->assertOk();
        }
        $this->assertDatabaseCount('hrla_attendance_versions', 0);
    }

    public function test_html_export_entities_and_formulas_are_rejected(): void
    {
        $hr = $this->linkedHr();
        foreach (['<!DOCTYPE html [<!ENTITY x SYSTEM "file:///not-read">]><table><tr><td>&x;</td></tr></table>', '<table><tr><td>Code</td><td>Date</td></tr><tr><td>=1+1</td><td>2026-10-30</td></tr></table>'] as $content) {
            $this->actingAs($hr)->post(route('la.import.upload'), ['file' => UploadedFile::fake()->createWithContent('unsafe.xls', $content)])->assertSessionHasErrors('file');
        }
        $this->assertDatabaseCount('hrla_import_batches', 0);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) if (is_file($path)) unlink($path);
        parent::tearDown();
    }
}
