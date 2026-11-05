<?php

namespace App\Services\LeaveAttendance;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use ZipArchive;

class SpreadsheetReader
{
    public const FORMATS = ['xlsx' => 'Xlsx', 'xlsm' => 'Xlsx', 'xltx' => 'Xlsx', 'xltm' => 'Xlsx', 'xls' => 'Xls', 'xlt' => 'Xls', 'ods' => 'Ods', 'ots' => 'Ods', 'csv' => 'Csv', 'tsv' => 'Csv'];

    public function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        if (!isset(self::FORMATS[$extension]) || filesize($path) > 5 * 1024 * 1024) {
            $this->fail('Choose a supported spreadsheet up to 5 MB: XLSX, XLS, ODS, CSV or TSV.');
        }
        $book = null;
        try {
            // Device exports often contain HTML, XML or tab-separated text named .xls.
            // Identify the bytes, rather than forcing the reader named by the extension.
            $bytes = file_get_contents($path);
            if (str_starts_with($bytes, 'PK')) {
                $this->checkArchive($path);
                $reader = IOFactory::createReaderForFile($path, ['Xlsx', 'Ods']);
            } elseif (str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
                $reader = IOFactory::createReader('Xls');
            } else {
                $text = $this->text($bytes);
                if (stripos($text, 'urn:schemas-microsoft-com:office:spreadsheet') !== false) {
                    if (stripos($text, '<!DOCTYPE') !== false || stripos($text, '<!ENTITY') !== false) $this->fail('Unsupported spreadsheet XML declarations.');
                    $reader = IOFactory::createReader('Xml');
                } elseif (preg_match('/<table\b/i', $text)) {
                    return $this->readHtmlTable($text);
                } else {
                    return $this->readDelimited($path, 'csv');
                }
            }
            if (!$reader->canRead($path)) $this->fail('Unable to open this workbook. If password-protected, save an unencrypted copy before uploading.');
            $info = $reader->listWorksheetInfo($path)[0] ?? null;
            if (!$info) $this->fail('A header row and at least one data row are required.');
            if ($info['totalRows'] > 2031 || $info['totalColumns'] > 40) $this->fail('Use at most 40 columns, 2,000 data rows and 30 heading rows on the first worksheet.');
            $reader->setLoadSheetsOnly($info['worksheetName']);
            $reader->setReadFilter(new class implements IReadFilter {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= 2031 && Coordinate::columnIndexFromString($columnAddress) <= 40;
                }
            });
            // Keep number formats to distinguish native dates from employee numbers.
            // Never call getCalculatedValue(): formula cells are rejected, not evaluated.
            $book = $reader->load($path);
            $sheet = $book->getSheet(0);
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $cells = [];
                $iterator = $row->getCellIterator();
                $iterator->setIterateOnlyExistingCells(true);
                foreach ($iterator as $cell) {
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) $this->fail('Formula cells are unsupported. Upload values only.');
                    $value = $cell->getValue();
                    if ($value instanceof RichText) $value = $value->getPlainText();
                    if (is_numeric($value) && Date::isDateTime($cell, $value)) {
                        $date = Date::excelToDateTimeObject((float) $value, 'UTC', $book->getExcelCalendar());
                        $value = (float) $value < 1 ? $date->format('H:i:s')
                            : $date->format(Date::isDateTime($cell, $value, false) ? 'Y-m-d H:i:s' : 'Y-m-d');
                    } elseif (is_numeric($value)) {
                        $format = $cell->getStyle()->getNumberFormat()->getFormatCode();
                        // Zero-padding formats are common on employee code columns.
                        if (preg_match('/^0+$/D', $format)) $value = NumberFormat::toFormattedString($value, $format);
                    }
                    $cells[Coordinate::columnIndexFromString($cell->getColumn()) - 1] = $this->scalar($value);
                }
                if (array_filter($cells, fn ($value) => $value !== '')) $rows[$row->getRowIndex()] = $cells;
            }
            $result = $this->result($rows);
            $result[2]['excel_calendar'] = $book->getExcelCalendar();
            return $result;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $this->fail('Unable to read this spreadsheet. Upload a valid, unencrypted XLSX, XLS, ODS, CSV or TSV file.');
        } finally {
            $book?->disconnectWorksheets();
        }
    }

    private function readDelimited(string $path, string $extension): array
    {
        $text = $this->text(file_get_contents($path));
        if (preg_match('/^\s*</', $text)) $this->fail('No readable attendance table was found in this file.');
        $stream = fopen('php://temp', 'w+');
        try {
            fwrite($stream, $text);
            $delimiter = "\t";
            if ($extension === 'csv') {
                $best = 0;
                foreach ([',', ';', "\t", '|'] as $candidate) {
                    rewind($stream);
                    $widths = [];
                    for ($i = 0; $i < 30 && ($sample = fgetcsv($stream, null, $candidate, '"', '')) !== false; $i++) {
                        if (count($sample) > 1) $widths[count($sample)] = ($widths[count($sample)] ?? 0) + 1;
                    }
                    $score = 0;
                    foreach ($widths as $width => $count) $score = max($score, $count * 100 + $width);
                    if ($score > $best) { $best = $score; $delimiter = $candidate; }
                }
                if ($best === 0) $this->fail('No readable spreadsheet table was found. Upload a workbook or delimited attendance report.');
            }
            rewind($stream);
            $rows = [];
            $number = 0;
            while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $number++;
                if (count($cells) > 40) $this->fail('At most 40 upload columns are supported.');
                $cells = array_map(fn ($value) => $this->scalar($value), $cells);
                foreach ($cells as $value) if (str_starts_with($value, '=')) $this->fail('Formula cells are unsupported. Upload values only.');
                if (array_filter($cells, fn ($value) => $value !== '')) $rows[$number] = $cells;
                if (count($rows) > 2031) $this->fail('At most 2,000 data rows and 30 heading rows are supported.');
            }
            return $this->result($rows);
        } finally {
            fclose($stream);
        }
    }

    private function text(string $text): string
    {
        if (str_starts_with($text, "\xFF\xFE")) $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE');
        elseif (str_starts_with($text, "\xFE\xFF")) $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE');
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (!mb_check_encoding($text, 'UTF-8') && preg_match('/(?:charset\s*=\s*["\x27]?|encoding\s*=\s*["\x27])\s*(windows-1252|iso-8859-1)/i', $text, $encoding)) $text = mb_convert_encoding($text, 'UTF-8', $encoding[1]);
        if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) $this->fail('Unsupported file encoding or binary spreadsheet. Use a supported workbook or UTF-8/Unicode export.');
        return $text;
    }

    private function readHtmlTable(string $text): array
    {
        $text = preg_replace('/<!DOCTYPE\s+html(?:\s+(?:PUBLIC|SYSTEM)\s+[^>\[]*)?\s*>/i', '', $text);
        if (stripos($text, '<!DOCTYPE') !== false || stripos($text, '<!ENTITY') !== false) $this->fail('Unsupported spreadsheet declarations.');
        $dom = new \DOMDocument;
        $prior = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadHTML('<?xml encoding="UTF-8">'.$text, LIBXML_NONET)) $this->fail('Unable to read this HTML spreadsheet table.');
        } finally {
            libxml_clear_errors(); libxml_use_internal_errors($prior);
        }
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//img|//iframe|//object') as $node) $node->parentNode->removeChild($node);
        $best = null; $bestScore = -1;
        foreach ($xpath->query('//table') as $index => $table) {
            if ($index >= 20) break;
            $rows = []; $spans = []; $number = 0;
            foreach ($xpath->query('./tr|./thead/tr|./tbody/tr|./tfoot/tr', $table) as $tr) {
                if (++$number > 2031) $this->fail('At most 2,000 data rows and 30 heading rows are supported.');
                $cells = []; $column = 0;
                foreach ($xpath->query('./th|./td', $tr) as $td) {
                    while (isset($spans[$column])) { $cells[$column] = ''; $column++; }
                    $wide = max(1, (int)$td->getAttribute('colspan')); $high = max(1, (int)$td->getAttribute('rowspan'));
                    if ($column + $wide > 40 || $high > 2031) $this->fail('Spreadsheet table exceeds the supported row or column limits.');
                    $value = $this->scalar(str_replace("\xC2\xA0", ' ', $td->textContent));
                    if (str_starts_with($value, '=')) $this->fail('Formula cells are unsupported. Upload values only.');
                    for ($i = 0; $i < $wide; $i++, $column++) {
                        $cells[$column] = $i === 0 ? $value : '';
                        if ($high > 1) $spans[$column] = $high;
                    }
                }
                foreach ($spans as $col => $left) { if ($left <= 1) unset($spans[$col]); else $spans[$col]--; }
                if (array_filter($cells, fn ($value) => $value !== '')) $rows[$number] = $cells;
            }
            if (count($rows) < 2) continue;
            $header = ImportFormats::header($rows);
            $score = count(array_filter(ImportFormats::columns($rows[$header]), fn ($value) => $value !== null));
            if ($score > $bestScore) { $bestScore = $score; $best = $rows; }
        }
        if (!$best) $this->fail('No attendance table with a header and data rows was found in this export.');
        return $this->result($best);
    }

    private function checkArchive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) $this->fail('Select a valid spreadsheet workbook.');
        try {
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) $size += $zip->statIndex($i)['size'];
            if ($zip->numFiles > 500 || $size > 25000000) $this->fail('Workbook exceeds the 25 MB expanded-size or 500-entry limit.');
        } finally {
            $zip->close();
        }
    }

    private function scalar(mixed $value): string
    {
        if ($value !== null && !is_scalar($value)) $this->fail('Unsupported spreadsheet cell value.');
        $value = trim((string) $value);
        if (strlen($value) > 2000) $this->fail('A cell exceeds 2,000 characters.');
        return $value;
    }

    private function result(array $rows): array
    {
        if (count($rows) < 2) $this->fail('A header row and at least one data row are required.');
        $key = ImportFormats::header($rows);
        [$headers, $data, $repeated] = ImportFormats::table($rows, $key);
        if (!$data || count($data) > 2000) $this->fail('Use a header row followed by 1–2,000 attendance rows.');
        return [$headers, $data, ['rows' => $rows, 'header_row' => $key, 'repeated_headers' => $repeated]];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
