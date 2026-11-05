<?php

namespace Tests\Unit;

use App\Services\LeaveAttendance\AttendanceCalculator;
use Carbon\CarbonImmutable as Date;
use Tests\TestCase;

class AttendanceCalculatorTest extends TestCase
{
    public function test_rounding_half_up_and_midnight_preserves_date(): void
    {
        $calc = new AttendanceCalculator;
        foreach (['09:12' => '09:00', '09:15' => '09:30', '09:16' => '09:30', '09:44' => '09:30', '09:45' => '10:00'] as $input => $expected) {
            $this->assertSame('2026-10-29 '.$expected.':00', $calc->roundPunch(Date::parse('2026-10-29 '.$input))->toDateTimeString());
        }
        $this->assertSame('2026-10-30 00:00:00', $calc->roundPunch(Date::parse('2026-10-29 23:45:00'))->toDateTimeString());
    }

    public function test_grace_boundaries_and_original_duration_arithmetic(): void
    {
        $calc = new AttendanceCalculator;
        foreach (['09:12' => 'On Time', '09:15' => 'On Time', '09:16' => 'On Time', '09:44' => 'On Time', '09:45' => 'Late', '09:46' => 'Late'] as $input => $expected) {
            $result = $calc->evaluate('2026-10-29', false, false, '2026-10-29 09:00:00', '2026-10-29 17:00:00', '2026-10-29 '.$input.':00', '2026-10-29 16:30:00');
            $this->assertSame($expected, $result['status']);
        }
        $r = $calc->evaluate('2026-10-29', false, false, '2026-10-29 09:00:00', '2026-10-29 17:00:00', '2026-10-29 09:46:05', '2026-10-29 16:14:10');
        $this->assertSame('Late / Early off', $r['status']);
        $this->assertTrue($r['late']);
        $this->assertTrue($r['early']);
        $this->assertSame(23285, $r['work_seconds']);
        $this->assertSame(28800, $r['actual_seconds']);
        $this->assertSame(-5515, $r['diff_seconds']);
    }

    public function test_precedence_missing_punches_missing_schedule_and_unfinished_shifts(): void
    {
        $calc = new AttendanceCalculator;
        $run = fn ($off, $leave, $in, $out, $start = '2026-10-29 09:00:00', $end = '2026-10-29 17:00:00') => $calc->evaluate('2026-10-29', $off, $leave, $start, $end, $in, $out, Date::parse('2026-10-29 12:00:00'));
        $this->assertSame('Day off', $run(true, true, null, null)['status']);
        $this->assertSame('Leave', $run(false, true, null, null)['status']);
        $this->assertSame('Missing Time Out', $run(false, false, '2026-10-29 09:00:00', null)['status']);
        $this->assertSame('Missing Time In', $run(false, false, null, '2026-10-29 17:00:00')['status']);
        $this->assertNull($run(false, false, '2026-10-29 09:00:00', null)['work_seconds']);
        $this->assertNull($run(false, false, null, '2026-10-29 17:00:00')['diff_seconds']);
        $this->assertSame('In progress / future', $run(false, false, null, null)['status']);
        $this->assertSame('Missing shift configuration', $run(false, false, null, null, null, null)['status']);
        $this->assertSame('Absent', $calc->evaluate('2026-10-28', false, false, '2026-10-28 09:00:00', '2026-10-28 17:00:00', null, null, Date::parse('2026-10-29'))['status']);
    }

    public function test_overnight_and_unrounded_shift_boundaries(): void
    {
        $r = (new AttendanceCalculator)->evaluate('2026-10-29', false, false, '2026-10-29 22:10:00', '2026-10-30 06:10:00', '2026-10-29 22:44:00', '2026-10-30 05:45:00');
        $this->assertSame('On Time', $r['status']);
        $this->assertSame(28800, $r['actual_seconds']);
        $this->assertSame(25260, $r['work_seconds']);
    }
}
