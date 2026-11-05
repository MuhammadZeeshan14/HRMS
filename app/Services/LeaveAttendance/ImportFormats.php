<?php

namespace App\Services\LeaveAttendance;

use DateTimeImmutable;

/** Deterministic suggestions: ambiguous identities and dates are never guessed. */
class ImportFormats
{
    public const ALIASES = [
        'employee_no' => ['employee no', 'employee number', 'employee code', 'employee id', 'emp no', 'emp code', 'emp id', 'staff code', 'staff id', 'personnel no', 'personnel number', 'badge no', 'badge id', 'ac no', 'enroll number', 'user id', 'code', 'employee name', 'staff name', 'name', 'employee'],
        'shift_date' => ['date', 'shift date', 'attendance date', 'work date', 'working date', 'duty date', 'day'],
        'time_in' => ['time in', 'new time in', 'check in', 'check in time', 'clock in', 'in time', 'in', 'first in', 'first punch', 'arrival', 'arrival time', 'start time', 'login'],
        'time_out' => ['time out', 'new time out', 'check out', 'check out time', 'clock out', 'out time', 'out', 'last out', 'last punch', 'departure', 'departure time', 'end time', 'logout'],
        'timestamp' => ['timestamp', 'date time', 'datetime', 'punch time', 'punch datetime', 'event time', 'log time', 'transaction time', 'record time'],
        'direction' => ['direction', 'punch type', 'event type', 'in out', 'check type', 'attendance state', 'state', 'type'],
    ];

    public static function key(string $value): string
    {
        $value = preg_replace('/([a-z])([A-Z])/', '$1 $2', $value);
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($value)));
    }

    public static function columns(array $headers): array
    {
        $map = [];
        foreach (self::ALIASES as $field => $aliases) {
            $matches = array_keys(array_filter($headers, fn ($header) => in_array(self::key($header), $aliases, true)));
            $map[$field] = count($matches) === 1 ? $matches[0] : null;
        }
        return $map;
    }

    public static function header(array $source): int
    {
        $best = array_key_first($source);
        $score = 0;
        foreach (array_slice($source, 0, 30, true) as $number => $cells) {
            $map = self::columns($cells);
            $current = count(array_filter($map, fn ($value) => $value !== null));
            if ($map['employee_no'] !== null) $current += 2;
            if ($current > $score) { $score = $current; $best = $number; }
        }
        return $score >= 3 ? $best : array_key_first($source);
    }

    public static function table(array $source, int $header): array
    {
        $headers = [];
        $width = max(array_map(fn ($cells) => max(array_keys($cells)) + 1, $source));
        for ($i = 0; $i < $width; $i++) $headers[] = ($source[$header][$i] ?? '') !== '' ? $source[$header][$i] : 'Column '.($i + 1);
        $rows = [];
        $repeated = [];
        foreach ($source as $number => $cells) {
            if ($number <= $header) continue;
            $same = 0;
            foreach ($source[$header] as $column => $label) if ($label !== '' && self::key($cells[$column] ?? '') === self::key($label)) $same++;
            if ($same >= 2 && $same === count(array_filter($source[$header], fn ($value) => $value !== ''))) { $repeated[] = $number; continue; }
            $rows[] = ['row' => $number, 'cells' => $cells];
        }
        return [$headers, $rows, $repeated];
    }

    public static function clock(?string $value): ?string
    {
        $value = self::clean($value);
        if (preg_match('/^(?:0?\.\d+|0)$/D', $value) && (float)$value < 1) {
            $seconds = (int)round((float)$value * 86400);
            if ($seconds >= 86400) return null;
            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }
        if (preg_match('/^(\d{1,2})([0-5]\d)$/D', $value, $compact) && (int)$compact[1] <= 23) $value = $compact[1].':'.$compact[2];
        if (!preg_match('/^(\d{1,2})\s*[:.]\s*([0-5]?\d)(?:\s*:\s*([0-5]?\d)(?:\.\d+)?)?\s*(AM|PM)?$/iD', $value, $m)) return null;
        $hour = (int)$m[1];
        if (!empty($m[4])) {
            if ($hour < 1 || $hour > 12) return null;
            $hour = $hour % 12 + (strtoupper($m[4]) === 'PM' ? 12 : 0);
        } elseif ($hour > 23) return null;
        return sprintf('%02d:%02d:%02d', $hour, (int)$m[2], (int)($m[3] ?? 0));
    }

    public static function split(?string $value): array
    {
        $value = self::clean($value);
        if (preg_match('/^(.+?)[ T,]+(\d{1,2}\s*[:.]\s*\d{1,2}(?:\s*:\s*\d{1,2}(?:\.\d+)?)?\s*(?:AM|PM)?)$/iD', $value, $m)) return [$m[1], self::clock($m[2])];
        return [$value, null];
    }

    public static function date(?string $value, string $order = 'auto', int $calendar = 1900): ?string
    {
        $value = self::clean($value);
        if (self::serial($value)) return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value, 'UTC', $calendar)->format('Y-m-d');
        [$value] = self::split($value);
        $value = preg_replace('/^(?:Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)[,]?\s+/i', '', $value);
        $formats = ['Y-m-d', 'Y/m/d', 'Y.m.d', 'j M Y', 'j F Y', 'j M, Y', 'j F, Y', 'j-M-Y', 'j-F-Y', 'j/M/Y', 'M j, Y', 'F j, Y', 'M j Y', 'F j Y'];
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/D', $value, $m)) {
            $a = (int)$m[1]; $b = (int)$m[2];
            if ($order === 'auto') {
                if ($a > 12 && $b <= 12) $order = 'dmy';
                elseif ($b > 12 && $a <= 12) $order = 'mdy';
                elseif ($a === $b) $order = 'dmy';
                else return null;
            }
            $day = $order === 'dmy' ? $a : $b;
            $month = $order === 'dmy' ? $b : $a;
            return checkdate($month, $day, (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $month, $day) : null;
        }
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && (!$errors || !$errors['warning_count'] && !$errors['error_count'])) return $date->format('Y-m-d');
        }
        return null;
    }

    public static function order(array $values): string
    {
        $orders = [];
        foreach ($values as $value) {
            [$date] = self::split($value);
            if (!preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/D', $date, $m)) continue;
            if ((int)$m[1] > 12 && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) $orders['dmy'] = true;
            if ((int)$m[2] > 12 && checkdate((int)$m[1], (int)$m[2], (int)$m[3])) $orders['mdy'] = true;
        }
        return count($orders) === 1 ? array_key_first($orders) : 'auto';
    }

    public static function clean(?string $value): string
    {
        $value = preg_replace('/[\x{200E}\x{200F}\x{FEFF}]/u', '', $value ?? '');
        $value = trim(preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $value));
        return preg_replace_callback('/\b([ap])\s*\.?\s*m\.?/i', fn ($m) => strtoupper($m[1]).'M', $value);
    }

    public static function serial(string $value): bool
    {
        return (bool)preg_match('/^\d{5}(?:\.\d+)?$/D', $value) && (float)$value >= 20000 && (float)$value <= 80000;
    }

    public static function stamp(?string $value, string $order = 'auto', int $calendar = 1900): ?string
    {
        $value = self::clean($value);
        if (self::serial($value)) return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value, 'UTC', $calendar)->format('Y-m-d H:i:s');
        [$date, $clock] = self::split($value);
        $day = self::date($date, $order, $calendar);
        return $day && $clock ? $day.' '.$clock : null;
    }
}
