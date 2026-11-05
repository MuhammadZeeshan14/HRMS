<?php

namespace App\Services\LeaveAttendance;

use App\Models\LeaveAttendance\ImportBatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ImportInterpreter
{
    public function __construct(private Access $access, private AttendanceService $attendance) {}

    public function suggest(array $headers, array $rows = [], ?User $actor = null): array
    {
        $map = ImportFormats::columns($headers);
        // A value-based suggestion is made only if exactly one column matches known codes.
        if ($map['employee_no'] === null && $actor && $rows) {
            $codes = $this->access->employees($actor)->pluck('employee_no')->all();
            $candidates = [];
            foreach (array_keys($headers) as $column) {
                $values = array_values(array_filter(array_map(fn ($row) => $row['cells'][$column] ?? '', array_slice($rows, 0, 25)), fn ($value) => $value !== ''));
                if ($values && count(array_filter($values, fn ($value) => in_array($value, $codes, true))) / count($values) >= .8) $candidates[] = $column;
            }
            if (count($candidates) === 1) $map['employee_no'] = $candidates[0];
        }
        if ($map['shift_date'] === null) {
            $dates = [];
            foreach (array_keys($headers) as $column) {
                $values = array_values(array_filter(array_map(fn ($row) => $row['cells'][$column] ?? '', array_slice($rows, 0, 25)), fn ($value) => $value !== ''));
                if ($values && count(array_filter($values, fn ($value) => ImportFormats::date($value) !== null && ImportFormats::split($value)[1] === null)) / count($values) >= .8) $dates[] = $column;
            }
            if (count($dates) === 1) $map['shift_date'] = $dates[0];
        }
        $mode = $map['timestamp'] !== null && $map['direction'] !== null && $map['time_in'] === null && $map['time_out'] === null ? 'punches' : 'daily';
        foreach (['shift_date', 'time_in', 'time_out'] as $field) $map[$field] ??= -1;
        $map['_options'] = ['layout' => $mode, 'date_order' => 'auto', 'employee_match' => 'auto'];
        return $map;
    }

    public function rows(ImportBatch $batch, array $map, User $actor): array
    {
        $options = $map['_options'] ?? [];
        $mode = $options['layout'] ?? 'daily';
        $calendar = (int)($map['_source']['excel_calendar'] ?? 1900);
        $fields = $mode === 'punches' ? ['employee_no', 'shift_date', 'timestamp', 'direction'] : ImportService::FIELDS;
        foreach ($fields as $field) {
            $required = in_array($field, $mode === 'punches' ? ['employee_no', 'timestamp', 'direction'] : ['employee_no'], true);
            if (!isset($map[$field]) || ($required && (int)$map[$field] < 0) || ((int)$map[$field] >= 0 && !array_key_exists((int)$map[$field], $batch->headers))) {
                throw ValidationException::withMessages(['mapping' => 'Select the employee and required attendance columns.']);
            }
        }
        if (in_array((int)$map['employee_no'], array_map(fn ($field) => (int)$map[$field], array_values(array_diff($fields, ['employee_no']))), true)
            || ($mode === 'daily' && (int)$map['time_in'] >= 0 && (int)$map['time_in'] === (int)$map['time_out'])
            || ($mode === 'punches' && (int)$map['timestamp'] === (int)$map['direction'])) {
            throw ValidationException::withMessages(['mapping' => 'Employee, check-in/check-out and punch direction must use distinct source columns.']);
        }
        $employees = $this->access->employees($actor)->get();
        $dateValues = [];
        foreach ($batch->rows as $row) foreach (array_diff($fields, ['employee_no', 'direction']) as $field) $dateValues[] = $row['cells'][(int)$map[$field]] ?? '';
        $order = $options['date_order'] ?? 'auto';
        if ($order === 'auto') $order = ImportFormats::order($dateValues);
        $rows = [];
        $groups = [];
        $schedules = [];
        $schedule = function ($employee, $day) use (&$schedules) {
            if (!$employee || !$day) return null;
            $key = $employee->id.'|'.$day;
            if (!array_key_exists($key, $schedules)) {
                $current = $this->attendance->latest($employee->id, $day);
                $schedules[$key] = $current ? $current->only(['shift_id', 'shift_name', 'shift_start', 'shift_end']) : $this->attendance->schedule($employee->id, $day);
            }
            return $schedules[$key];
        };
        foreach ($batch->rows as $row) {
            $raw = [];
            foreach ($fields as $field) $raw[$field] = $row['cells'][(int)$map[$field]] ?? null;
            $identifier = trim($raw['employee_no'] ?? '');
            $matches = $employees->filter(fn ($employee) => $employee->employee_no === $identifier);
            if ($matches->isEmpty() && ($options['employee_match'] ?? 'auto') === 'auto') {
                $matches = $employees->filter(fn ($employee) => ImportFormats::key($employee->employee_no) === ImportFormats::key($identifier));
                if ($matches->isEmpty()) $matches = $employees->filter(fn ($employee) => ImportFormats::key($employee->display_name) === ImportFormats::key($identifier));
                if ($matches->isEmpty() && ctype_digit($identifier)) $matches = $employees->filter(fn ($employee) => ctype_digit($employee->employee_no) && ltrim($employee->employee_no, '0') === ltrim($identifier, '0'));
            }
            $employee = $matches->count() === 1 ? $matches->first() : null;
            $code = $employee?->employee_no ?? $identifier;
            $notes = [];
            if ($employee && $identifier !== $code) $notes[] = 'Matched “'.$identifier.'” to employee '.$code.'.';
            if ($order !== 'auto') $notes[] = 'Numeric dates interpreted '.($order === 'dmy' ? 'day/month/year' : 'month/day/year').'.';
            $day = ImportFormats::date($raw['shift_date'], $order, $calendar);
            if ($mode === 'punches') {
                [$datePart, $clock] = ImportFormats::split($raw['timestamp']);
                $date = ImportFormats::date($datePart, $order, $calendar);
                $canonical = ImportFormats::stamp($raw['timestamp'], $order, $calendar);
                if ($canonical) { $date = substr($canonical, 0, 10); $clock = substr($canonical, 11); }
                $stamp = $canonical ?? ($date && $clock ? $date.' '.$clock : $raw['timestamp']);
                $direction = ImportFormats::key($raw['direction'] ?? '');
                $field = in_array($direction, ['in', 'i', 'check in', 'clock in', 'entry', 'sign in'], true) ? 'time_in'
                    : (in_array($direction, ['out', 'o', 'check out', 'clock out', 'exit', 'sign out'], true) ? 'time_out' : null);
                if (!$day && empty($raw['shift_date']) && $date && $clock && $employee && $field) {
                    $day = $this->attendanceDay($date, $stamp, $field, $employee, $schedule);
                    if ($day !== $date) $notes[] = 'Punch linked to the previous day’s overnight shift.';
                }
                $day ??= empty($raw['shift_date']) ? $date : $raw['shift_date'];
                $data = ['employee_no' => $code, 'shift_date' => $day, 'time_in' => null, 'time_out' => null];
                if (!$field || !$date || !$clock || !$employee || !ImportFormats::date($day)) {
                    $data[$field ?? 'time_in'] = $field ? $stamp : 'Unrecognized IN/OUT direction: '.($raw['direction'] ?? 'blank');
                    $rows[] = $this->row($row['row'], $data, ['employee_no' => $identifier, 'shift_date' => $raw['shift_date'], 'time_in' => $raw['timestamp'], 'time_out' => $raw['direction']], $notes);
                    continue;
                }
                $key = $employee->id.'|'.$day;
                if (!isset($groups[$key])) $groups[$key] = $this->row($row['row'], $data, ['employee_no' => $identifier, 'shift_date' => $raw['shift_date'], 'time_in' => null, 'time_out' => null], $notes);
                $group = &$groups[$key];
                $group['source_rows'][] = $row['row'];
                $old = $group['data'][$field];
                if (!$old || ($field === 'time_in' ? $stamp < $old : $stamp > $old)) {
                    $group['data'][$field] = $stamp;
                    $group['source_data'][$field] = $raw['timestamp'];
                }
                $group['interpretation_notes'] = array_values(array_unique(array_merge($group['interpretation_notes'], $notes)));
                unset($group);
                continue;
            }
            if (!$day && empty($raw['shift_date'])) {
                foreach (['time_in', 'time_out'] as $field) {
                    $canonical = ImportFormats::stamp($raw[$field], $order, $calendar);
                    if ($canonical) {
                        $candidate = substr($canonical, 0, 10); $clock = substr($canonical, 11);
                        $day = $employee ? $this->attendanceDay($candidate, $candidate.' '.$clock, $field, $employee, $schedule) : $candidate;
                        $notes[] = 'Attendance date derived from '.$field.' and assigned shift; verify '.$day.'.';
                        break;
                    }
                }
            }
            $data = ['employee_no' => $code, 'shift_date' => $day ?? $raw['shift_date'], 'time_in' => null, 'time_out' => null];
            foreach (['time_in', 'time_out'] as $field) {
                if (blank($raw[$field])) continue;
                $canonical = ImportFormats::stamp($raw[$field], $order, $calendar);
                if ($canonical) $data[$field] = $canonical;
                elseif (($clock = ImportFormats::clock($raw[$field])) && $day && $employee && ($shift = $schedule($employee, $day))) {
                    $date = $day;
                    if (substr($shift['shift_end'], 0, 10) !== $day) {
                        $next = CarbonImmutable::parse($day)->addDay()->format('Y-m-d');
                        $boundary = $shift[$field === 'time_in' ? 'shift_start' : 'shift_end'];
                        $a = abs(strtotime($day.' '.$clock) - strtotime($boundary));
                        $b = abs(strtotime($next.' '.$clock) - strtotime($boundary));
                        if ($a === $b) { $data[$field] = $raw[$field]; $notes[] = 'Clock date is uncertain; select an explicit punch date.'; continue; }
                        if ($b < $a) $date = $next;
                    }
                    $data[$field] = $date.' '.$clock;
                    $notes[] = 'Clock-only '.$field.' dated from assigned shift; verify '.$date.'.';
                } else $data[$field] = $raw[$field];
            }
            $rows[] = $this->row($row['row'], $data, $raw, $notes);
        }
        foreach ($groups as $group) {
            $group['source_rows'] = array_values(array_unique($group['source_rows']));
            $group['interpretation_notes'][] = 'Grouped file rows '.implode(', ', $group['source_rows']).'; earliest IN and latest OUT. Verify multiple punches.';
            $group['interpreted_data'] = $group['data'];
            $rows[] = $group;
        }
        usort($rows, fn ($a, $b) => $a['row'] <=> $b['row']);
        return $rows;
    }

    private function attendanceDay(string $date, string $stamp, string $field, $employee, callable $schedule): string
    {
        $previous = CarbonImmutable::parse($date)->subDay()->format('Y-m-d');
        $prior = $schedule($employee, $previous);
        $current = $schedule($employee, $date);
        $boundary = $field === 'time_in' ? 'shift_start' : 'shift_end';
        if ($prior && substr($prior['shift_end'], 0, 10) !== $previous) {
            $distance = abs(strtotime($stamp) - strtotime($prior[$boundary]));
            if ($distance <= 12 * 3600 && (!$current || $distance < abs(strtotime($stamp) - strtotime($current[$boundary])))) return $previous;
        }
        return $date;
    }

    private function row(int $number, array $data, array $source, array $notes): array
    {
        return ['row' => $number, 'data' => $data, 'source_data' => $source, 'interpreted_data' => $data,
            'interpretation_notes' => $notes, 'auto_format_fields' => array_values(array_filter(['shift_date', 'time_in', 'time_out'], fn ($field) => ($data[$field] ?? null) !== ($source[$field] ?? null))), 'source_rows' => [$number], 'excluded' => false];
    }
}
