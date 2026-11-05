<?php

namespace Tests\Unit;

use App\Support\HrlaTime;
use Tests\TestCase;

class HrlaTimeTest extends TestCase
{
    public function test_segmented_clock_requires_valid_hours_and_minutes(): void
    {
        $this->assertSame('12:00 AM', HrlaTime::fromParts('12', '00', 'AM'));
        $this->assertSame('9:05 PM', HrlaTime::fromParts('09', '5', 'PM'));
        $this->assertNull(HrlaTime::fromParts('', '', 'AM'));
        foreach ([['13', '00', 'AM'], ['0', '00', 'PM'], ['9', '60', 'AM'], ['9', '', 'AM'], ['9', '10', 'bad'], ['1e1', '10', 'AM']] as [$hour,$minute,$period]) {
            $this->assertSame('invalid clock', HrlaTime::fromParts($hour, $minute, $period));
        }
    }

    public function test_noon_midnight_and_meridiem_parsing_are_unambiguous(): void
    {
        foreach (['12:00 AM' => '00:00:00', '12:00 PM' => '12:00:00', '1:05 pm' => '13:05:00', '09:15AM' => '09:15:00'] as $input => $expected) {
            $this->assertSame($expected, HrlaTime::parseClock($input));
        }
        foreach (['0:15 AM', '13:00 PM', '9:60 AM', '9:05', '9:05 PM extra'] as $invalid) {
            $this->assertNull(HrlaTime::parseClock($invalid));
        }
        $this->assertSame('12:00 AM', HrlaTime::clock('00:00:47'));
        $this->assertSame('12:00 PM', HrlaTime::clock('12:00:47'));
        $this->assertSame('2026-10-30 6:05 AM', HrlaTime::stamp('2026-10-30 06:05:47'));
    }

    public function test_visible_minute_precision_preserves_original_seconds_and_overnight_dates(): void
    {
        $this->assertSame('2026-10-29 21:16:37', HrlaTime::combine('2026-10-29', '9:16 PM', '2026-10-29 21:16:37'));
        $this->assertSame('2026-10-30 06:05:47', HrlaTime::combine('2026-10-30', '6:05 AM', '2026-10-29 06:05:47'));
        $this->assertSame('2026-10-29 21:17:00', HrlaTime::combine('2026-10-29', '9:17 PM', '2026-10-29 21:16:37'));
        $this->assertNull(HrlaTime::combine(null, null, '2026-10-29 21:16:37'));
        $this->assertSame('invalid date or AM/PM time', HrlaTime::combine('2026-10-29', '', null));
    }
}
