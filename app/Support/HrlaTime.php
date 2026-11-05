<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class HrlaTime
{
    public static function clock(mixed $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }
        try {
            return CarbonImmutable::parse($value, config('app.timezone'))->format('g:i A');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    public static function stamp(mixed $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }
        try {
            return CarbonImmutable::parse($value, config('app.timezone'))->format('Y-m-d g:i A');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    public static function parseClock(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(0?[1-9]|1[0-2]):([0-5][0-9])\s*(AM|PM)$/iD', trim($value), $match)) {
            return null;
        }
        $hour = ((int) $match[1] % 12) + (strtoupper($match[3]) === 'PM' ? 12 : 0);

        return sprintf('%02d:%s:00', $hour, $match[2]);
    }

    public static function fromParts(mixed $hour, mixed $minute, mixed $period): ?string
    {
        if (blank($hour) && blank($minute)) {
            return null;
        }
        if (! is_scalar($hour) || ! is_scalar($minute) || ! is_string($period)
            || ! preg_match('/^(0?[1-9]|1[0-2])$/D', (string) $hour)
            || ! preg_match('/^[0-5]?[0-9]$/D', (string) $minute)
            || ! in_array($period, ['AM', 'PM'], true)) {
            return 'invalid clock';
        }

        return (int) $hour.':'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT).' '.$period;
    }

    public static function parseStamp(mixed $value): mixed
    {
        if (! is_string($value) || ! preg_match('/^(\d{4}-\d{2}-\d{2})\s+(.+)$/D', trim($value), $match)) {
            return $value;
        }
        $clock = self::parseClock($match[2]);

        return $clock ? $match[1].' '.$clock : $value;
    }

    // Editing a date or leaving a displayed minute unchanged must not erase original seconds.
    public static function combine(mixed $date, mixed $clock, mixed $original): ?string
    {
        if (blank($date) && blank($clock)) {
            return null;
        }
        $parsed = self::parseClock($clock);
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ! $parsed) {
            return 'invalid date or AM/PM time';
        }
        if (is_string($original) && preg_match('/^(?:\d{4}-\d{2}-\d{2}[ T])?(\d{2}:\d{2}):(\d{2})$/D', $original, $match) && substr($parsed, 0, 5) === $match[1]) {
            $parsed = $match[1].':'.$match[2];
        }

        return $date.' '.$parsed;
    }
}
