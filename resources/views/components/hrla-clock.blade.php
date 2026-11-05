@props(['name','value'=>null,'label','required'=>false,'useOld'=>true])
@php
    $formatted = \App\Support\HrlaTime::clock($value,'');
    preg_match('/^(\d{1,2}):(\d{2}) (AM|PM)$/D',$formatted,$parts);
    $hour = $parts[1] ?? '';
    $minute = $parts[2] ?? '';
    $period = $parts[3] ?? 'AM';
    if($useOld) {
        $hour = old($name.'_hour',$hour);
        $minute = old($name.'_minute',$minute);
        $period = old($name.'_period',$period);
    }
@endphp
<div class="hrla-clock" data-hrla-clock role="group" aria-label="{{ $label }}">
<input type="hidden" name="{{ $name }}" value="{{ $formatted }}" data-clock-value>
<input type="number" name="{{ $name }}_hour" value="{{ $hour === '' ? '' : str_pad($hour,2,'0',STR_PAD_LEFT) }}" min="1" max="12" step="1" inputmode="numeric" placeholder="--" aria-label="{{ $label }} hours" data-clock-hour @required($required)>
<span class="hrla-clock-colon" aria-hidden="true">:</span>
<input type="number" name="{{ $name }}_minute" value="{{ $minute === '' ? '' : str_pad($minute,2,'0',STR_PAD_LEFT) }}" min="0" max="59" step="1" inputmode="numeric" placeholder="--" aria-label="{{ $label }} minutes" data-clock-minute @required($required)>
<select name="{{ $name }}_period" aria-label="{{ $label }} AM/PM" data-clock-period><option value="AM" @selected($period==='AM')>AM</option><option value="PM" @selected($period==='PM')>PM</option></select>
</div>
