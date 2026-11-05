<?php

namespace App\Services\LeaveAttendance;

use App\Models\LeaveAttendance\ImportBatch;
use App\Models\LeaveAttendance\LeaveApplication;
use App\Models\User;
use App\Support\HrlaTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportService
{
    public const FIELDS = ['employee_no', 'shift_date', 'time_in', 'time_out'];

    public function __construct(private AttendanceService $attendance, private Access $access) {}

    public function propose(array $headers): array
    {
        return array_intersect_key(ImportFormats::columns($headers), array_flip(self::FIELDS));
    }

    public function preview(ImportBatch $batch, array $mapping, User $actor): array
    {
        abort_unless($this->access->hr($actor), 403);
        if (isset($mapping['_options'])) return $this->validateRows(app(ImportInterpreter::class)->rows($batch, $mapping, $actor), $actor, $mapping);
        if (count(array_unique(array_values($mapping))) !== 4) {
            throw ValidationException::withMessages(['mapping' => 'Choose four distinct columns.']);
        }
        foreach (self::FIELDS as $field) {
            if (! isset($mapping[$field]) || ! array_key_exists((int) $mapping[$field], $batch->headers)) {
                throw ValidationException::withMessages(['mapping' => 'Map every required field.']);
            }
        }
        $rows = [];
        foreach ($batch->rows as $row) {
            $data = [];
            foreach (self::FIELDS as $field) {
                $value = $row['cells'][(int) $mapping[$field]] ?? '';
                $data[$field] = $value === '' ? null : $value;
            }
            $rows[] = ['row' => $row['row'], 'data' => $data, 'source_data' => $data, 'excluded' => false];
        }

        return $this->validateRows($rows, $actor, $mapping);
    }

    public function revision(ImportBatch $batch): string
    {
        return hash('sha256', json_encode([$batch->status, $batch->mapping, $batch->results], JSON_THROW_ON_ERROR));
    }

    public function review(ImportBatch $batch, array $edits, User $actor, string $revision, ?string $dateOrder = null): array
    {
        abort_unless($this->access->hr($actor), 403);

        return DB::transaction(function () use ($batch, $edits, $actor, $revision, $dateOrder) {
            $batch = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless($batch->actor_id === $actor->id, 403);
            if ($batch->status !== 'mapped' || !is_array($batch->results)) {
                throw ValidationException::withMessages(['review' => 'Confirm the mapping before editing. Saved imports cannot be changed.']);
            }
            $this->checkRevision($batch, $revision);
            $rows = collect($batch->results)->keyBy('row');
            foreach ($edits as $edit) {
                $row = $rows->get($edit['row']);
                if (!$row) throw ValidationException::withMessages(['review' => 'An edited row does not belong to this upload. Reload the preview.']);
                $row['target'] ??= $row['employee_id'] ? $row['employee_id'].'|'.$row['data']['shift_date'] : null;
                foreach (self::FIELDS as $field) {
                    if (array_key_exists($field, $edit)) {
                        $row['data'][$field] = $edit[$field] === '' ? null : $edit[$field];
                        unset($row['edit_parts'][$field]);
                    }
                }
                foreach (['time_in', 'time_out'] as $field) {
                    if (array_key_exists($field.'_hour', $edit) || array_key_exists($field.'_minute', $edit)) {
                        $row['edit_parts'][$field] = ['date' => $edit[$field.'_date'] ?? '', 'hour' => $edit[$field.'_hour'] ?? '', 'minute' => $edit[$field.'_minute'] ?? '', 'period' => $edit[$field.'_period'] ?? 'AM'];
                        // Empty time boxes mean no punch even when a suggested date is displayed.
                        $row['data'][$field] = blank($edit[$field.'_hour'] ?? null) && blank($edit[$field.'_minute'] ?? null) ? null
                            : HrlaTime::combine($edit[$field.'_date'] ?? null, HrlaTime::fromParts($edit[$field.'_hour'] ?? null, $edit[$field.'_minute'] ?? null, $edit[$field.'_period'] ?? null), $row['data'][$field]);
                    }
                }
                if (array_key_exists('excluded', $edit)) $row['excluded'] = (bool)$edit['excluded'];
                $row['refresh_current'] = (bool)($edit['refresh_current'] ?? false);
                $rows->put($edit['row'], $row);
            }
            $mapping = $batch->mapping ?? [];
            if ($dateOrder !== null) $mapping['_options']['date_order'] = $dateOrder;
            $results = $this->validateRows($rows->values()->all(), $actor, $mapping);
            $batch->update(['results' => $results, 'mapping' => $mapping]);

            return $results;
        }, 3);
    }

    private function checkRevision(ImportBatch $batch, ?string $revision): void
    {
        if ($revision !== null && !hash_equals($this->revision($batch), $revision)) {
            throw ValidationException::withMessages(['revision' => 'This upload was changed in another tab. Reload it before reviewing or saving.']);
        }
    }

    private function validateRows(array $rows, User $actor, array $mapping = []): array
    {
        $employees = $this->access->employees($actor)->with('department')->get()->keyBy('employee_no');
        $order = $mapping['_options']['date_order'] ?? 'auto';
        $calendar = (int)($mapping['_source']['excel_calendar'] ?? 1900);
        if ($order === 'auto') {
            $values = [];
            foreach ($rows as $row) foreach (['data', 'source_data'] as $key) foreach (['shift_date', 'time_in', 'time_out'] as $field) $values[] = $row[$key][$field] ?? null;
            $order = ImportFormats::order($values);
            if ($order === 'auto') {
                $evidence = [];
                foreach ($rows as $row) {
                    $rawDate = $row['data']['shift_date'] ?? null;
                    $dmy = ImportFormats::date($rawDate, 'dmy', $calendar);
                    $mdy = ImportFormats::date($rawDate, 'mdy', $calendar);
                    if (!$dmy || !$mdy || $dmy === $mdy) continue;
                    $stamp = ImportFormats::stamp($row['data']['time_in'] ?? null, 'auto', $calendar);
                    if ($stamp && substr($stamp, 0, 10) === $dmy) $evidence['dmy'] = true;
                    if ($stamp && substr($stamp, 0, 10) === $mdy) $evidence['mdy'] = true;
                }
                if (count($evidence) === 1) $order = array_key_first($evidence);
            }
        }
        $results = [];
        $seen = [];
        $contexts = [];
        foreach ($rows as $row) {
            $data = $row['data'];
            $notes = $row['interpretation_notes'] ?? [];
            $fixed = $row['auto_format_fields'] ?? [];
            $before = $data;
            $data['shift_date'] = ImportFormats::date($data['shift_date'], $order, $calendar) ?? $data['shift_date'];
            $employee = $employees->get($data['employee_no']);
            $day = ImportFormats::date($data['shift_date'], $order, $calendar);
            $formatSchedule = null;
            foreach (['time_in', 'time_out'] as $field) {
                $raw = ImportFormats::clean($data[$field]);
                if (in_array(mb_strtolower($raw), ['', '-', '—', 'n/a', 'na', 'null'], true)) { $data[$field] = null; continue; }
                if ($stamp = ImportFormats::stamp($raw, $order, $calendar)) { $data[$field] = $stamp; continue; }
                $clock = ImportFormats::clock($raw);
                if ($clock) $data[$field] = $clock;
                if ($clock && $employee && $day) {
                    if ($formatSchedule === null) {
                        $contextKey = $employee->id.'|'.$day;
                        if (!array_key_exists($contextKey, $contexts)) {
                            $current = $this->attendance->latest($employee->id, $day);
                            $contexts[$contextKey] = [$current, $current ? $current->only(['shift_id', 'shift_name', 'shift_start', 'shift_end']) : $this->attendance->schedule($employee->id, $day)];
                        }
                        $formatSchedule = $contexts[$contextKey][1];
                    }
                    if ($formatSchedule) {
                        $date = $day;
                        if (substr($formatSchedule['shift_end'], 0, 10) !== $day) {
                            $next = \Carbon\CarbonImmutable::parse($day)->addDay()->format('Y-m-d');
                            $boundary = $formatSchedule[$field === 'time_in' ? 'shift_start' : 'shift_end'];
                            $a = abs(strtotime($day.' '.$clock) - strtotime($boundary));
                            $b = abs(strtotime($next.' '.$clock) - strtotime($boundary));
                            if ($a === $b) continue;
                            if ($b < $a) $date = $next;
                        }
                        $data[$field] = $date.' '.$clock;
                    }
                }
            }
            foreach (['shift_date', 'time_in', 'time_out'] as $field) {
                if (($before[$field] ?? null) !== ($data[$field] ?? null)) {
                    $fixed[] = $field;
                    unset($row['edit_parts'][$field]);
                }
            }
            $fixed = array_values(array_unique($fixed));
            $timeRule = fn ($field) => ImportFormats::clock($data[$field]) !== null ? 'nullable|date_format:H:i:s' : 'nullable|date_format:Y-m-d H:i:s';
            $v = Validator::make($data, ['employee_no' => 'required|string', 'shift_date' => 'required|date_format:Y-m-d', 'time_in' => $timeRule('time_in'), 'time_out' => $timeRule('time_out')], [
                'shift_date.date_format' => 'The attendance date is ambiguous or invalid. Choose the correct date order in Fix formats, or select a valid date.',
                'time_in.date_format' => 'Check-in could not be interpreted. Enter a valid time or date/time.',
                'time_out.date_format' => 'Check-out could not be interpreted. Enter a valid time or date/time.',
            ]);
            $errors = $v->errors()->all();
            $employee = $employees->get($data['employee_no']);
            $latest = null;
            $schedule = null;
            $calculation = null;
            $target = null;
            $expected = null;
            if (!$employee) $errors[] = 'Unknown or unauthorized employee code.';
            elseif ($actor->employee?->id === $employee->id) $errors[] = 'Self-entry must use an employee correction request.';
            if (!$errors) {
                foreach (['time_in', 'time_out'] as $field) {
                    if (ImportFormats::clock($data[$field]) !== null) $errors[] = $formatSchedule
                        ? 'The punch date is uncertain. Select an explicit date for '.str_replace('_', ' ', $field).'.'
                        : 'Assign an effective shift so clock-only punches can be dated automatically.';
                }
                $errors = array_values(array_unique($errors));
            }
            if ($employee && !$v->errors()->has('shift_date')) {
                $target = $employee->id.'|'.$data['shift_date'];
                if (!isset($contexts[$target])) {
                    $current = $this->attendance->latest($employee->id, $data['shift_date']);
                    $contexts[$target] = [$current, $current ? $current->only(['shift_id', 'shift_name', 'shift_start', 'shift_end']) : $this->attendance->schedule($employee->id, $data['shift_date'])];
                }
                [$latest, $schedule] = $contexts[$target];
                $sameTarget = ($row['target'] ?? null) === $target || (!isset($row['target']) && ($row['employee_id'] ?? null) === $employee->id && ($row['data']['shift_date'] ?? null) === $data['shift_date']);
                $expected = $sameTarget && array_key_exists('base_version_id', $row) && empty($row['refresh_current']) ? $row['base_version_id'] : $latest?->id;
                if ($expected !== $latest?->id) $errors[] = 'Attendance changed since preview. Use Reload current, review the current punches, then validate again.';
                if (!$errors) {
                    try {
                        $this->attendance->validatePunches($data, $schedule);
                        $onLeave = LeaveApplication::where('employee_id', $employee->id)->where('status', 'approved')->where('starts_on', '<=', $data['shift_date'])->where('ends_on', '>=', $data['shift_date'])->exists();
                        $calculation = app(AttendanceCalculator::class)->evaluate($data['shift_date'], app(Calendar::class)->off($data['shift_date']), $onLeave,
                            $schedule['shift_start'], $schedule['shift_end'], $data['time_in'], $data['time_out']);
                    } catch (ValidationException $e) {
                        $errors = array_merge($errors, array_merge(...array_values($e->errors())));
                    }
                }
            }
            $key = ($data['employee_no'] ?? '').'|'.($data['shift_date'] ?? '');
            $excluded = (bool)($row['excluded'] ?? false);
            if (!$excluded && isset($seen[$key])) {
                $errors[] = 'Duplicate employee/date within this workbook; only the first row is eligible.';
            }
            if (!$excluded) $seen[$key] = true;
            $source = $row['source_data'] ?? $row['data'];
            $original = $row['interpreted_data'] ?? $source;
            foreach (['time_in', 'time_out'] as $field) $original[$field] = HrlaTime::parseStamp($original[$field]);
            $edited = array_values(array_filter(self::FIELDS, fn ($field) => ($data[$field] ?? null) !== ($original[$field] ?? null)));
            $results[] = ['row' => $row['row'], 'data' => $data, 'source_data' => $source, 'employee_id' => $employee?->id,
                'employee_name' => $employee?->display_name, 'department' => $employee?->department?->name,
                'target' => $target, 'base_version_id' => $expected, 'current' => $latest?->only(['time_in', 'time_out']),
                'schedule' => $schedule, 'calculation' => $calculation, 'excluded' => $excluded, 'edit_parts' => $row['edit_parts'] ?? [], 'edited_fields' => $edited, 'errors' => $excluded ? [] : $errors,
                'interpreted_data' => $row['interpreted_data'] ?? null, 'interpretation_notes' => $notes, 'auto_format_fields' => $fixed, 'source_rows' => $row['source_rows'] ?? [$row['row']]];
        }

        return $results;
    }

    public function commit(ImportBatch $batch, User $actor, ?string $revision = null): array
    {
        abort_unless($this->access->hr($actor), 403);

        return DB::transaction(function () use ($batch, $actor, $revision) {
            $batch = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless($batch->actor_id === $actor->id, 403);
            if ($batch->status === 'committed') {
                return $batch->results;
            }
            $this->checkRevision($batch, $revision);
            if ($batch->status !== 'mapped' || ! $batch->results) {
                throw ValidationException::withMessages(['file' => 'Preview and confirm the field mapping before importing.']);
            }
            $counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0, 'excluded' => 0, 'rows' => []];
            foreach ($batch->results as $row) {
                if (!empty($row['excluded']) || $row['errors']) {
                    $counts['skipped']++;
                    $counts['excluded'] += !empty($row['excluded']) ? 1 : 0;
                    $row['outcome'] = !empty($row['excluded']) ? 'excluded' : 'invalid';
                    $counts['rows'][] = $row;

                    continue;
                }
                try {
                    $employee = $this->access->employees($actor)->find($row['employee_id']);
                    if (! $employee || $actor->employee?->id === $employee->id) {
                        throw ValidationException::withMessages(['employee' => 'Employee is no longer available or authorized for this import.']);
                    }
                    $note = empty($row['edited_fields']) ? '' : ' (reviewed edits: '.implode(', ', $row['edited_fields']).')';
                    if (count($row['source_rows'] ?? []) > 1) $note .= ' (source rows: '.implode(', ', $row['source_rows']).')';
                    $this->attendance->append($employee, $actor, $row['data'] + ['reason_type' => 'correction', 'reason' => 'Spreadsheet import '.$batch->filename.' row '.$row['row'].$note], 'spreadsheet import', $row['base_version_id'], ['batch_id' => $batch->id, 'source_row' => $row['row']]);
                    $counts['imported']++;
                    $row['outcome'] = 'imported';
                } catch (ValidationException $e) {
                    $counts['failed']++;
                    $row['errors'] = array_merge(...array_values($e->errors()));
                    $row['outcome'] = 'failed';
                }
                $counts['rows'][] = $row;
            }
            $batch->update(['status' => 'committed', 'committed_at' => now(), 'results' => $counts]);

            return $counts;
        }, 3);
    }
}
