@props(['name','value'=>null,'label','defaultDate'=>''])
@php
    $original = str_replace('T',' ', $value ?? '');
    $date = old($name.'_date', $original ? substr($original,0,10) : $defaultDate);
    $clock = old($name.'_clock', \App\Support\HrlaTime::clock($original,''));
@endphp
<div class="hrla-datetime-input" data-hrla-datetime="{{ $name }}">
<input type="hidden" name="{{ $name }}" value="{{ $original }}">
<label>{{ $label }} date<input type="date" name="{{ $name }}_date" value="{{ $date }}"></label>
<div class="hrla-clock-field"><span>{{ $label }} time</span><x-hrla-clock :name="$name.'_clock'" :value="$clock" :label="$label.' time'" /></div>
</div>
