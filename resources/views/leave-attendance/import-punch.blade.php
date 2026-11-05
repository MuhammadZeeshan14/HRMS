@php
    $raw = $row['data'][$field] ?? null;
    $validStamp = is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $raw);
    $timeOnly = is_string($raw) && preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $raw);
    $parts = $row['edit_parts'][$field] ?? null;
    $suggested = $row['schedule'][$field === 'time_in' ? 'shift_start' : 'shift_end'] ?? ($row['data']['shift_date'] ?? '');
    $punchDate = $validStamp ? substr($raw,0,10) : ($parts['date'] ?? ($timeOnly ? '' : substr($suggested,0,10)));
    $clock = $validStamp ? $raw : ($parts ? \App\Support\HrlaTime::fromParts($parts['hour'], $parts['minute'], $parts['period']) : ($timeOnly ? $raw : null));
@endphp
<div class="import-punch" data-import-punch="{{ $field }}" data-original="{{ $raw }}">
    <input type="date" value="{{ $punchDate }}" data-punch-date aria-label="{{ $label }} date, File row {{ $row['row'] }}">
    <x-hrla-clock :name="'import_'.$row['row'].'_'.$field" :value="$clock" :label="$label.' time, File row '.$row['row']" :use-old="false" />
    <button type="button" class="import-clear-punch" data-clear-punch>Clear {{ strtolower($label) }}</button>
    @if($timeOnly)<small class="import-error">Select the punch date, then validate.</small>@elseif($raw && !$validStamp)<small class="import-error">Unparsed: {{ $raw }}</small>@endif
</div>
