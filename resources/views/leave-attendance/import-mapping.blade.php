@php($options = $mapping['_options'] ?? [])
@if(!empty($mapping['_source']['rows']))
    <form method="post" action="{{ route('la.import.layout', $batch) }}" class="form-grid master-form la-form">
        @csrf<input type="hidden" name="revision" value="{{ $revision }}">
        <label class="wide">Detected header row<select name="header_row">@foreach(array_slice($mapping['_source']['rows'], 0, 30, true) as $number => $cells)<option value="{{ $number }}" @selected($number == $mapping['_source']['header_row'])>Row {{ $number }} · {{ str(implode(' · ', $cells))->limit(180) }}</option>@endforeach</select></label>
        <div class="form-actions wide"><button class="btn">Use this header &amp; suggest columns</button></div>
    </form>
    <p class="la-note">Report titles above this row are ignored. {{ count($mapping['_source']['repeated_headers'] ?? []) }} repeated header rows ignored. Changing the header resets reviewed edits.</p>
@endif
<form method="post" action="{{ route('la.import.map',$batch) }}" class="form-grid master-form la-form" data-import-mapping>
    @csrf<input type="hidden" name="revision" value="{{ $revision }}">
    <label>Source layout<select name="layout" data-import-layout><option value="daily" @selected(($options['layout'] ?? 'daily') === 'daily')>One row per employee/day</option><option value="punches" @selected(($options['layout'] ?? '') === 'punches')>Separate IN/OUT punch log rows</option></select></label>
    <label>Numeric date order<select name="date_order"><option value="auto" @selected(($options['date_order'] ?? 'auto') === 'auto')>Detect; flag ambiguous dates</option><option value="dmy" @selected(($options['date_order'] ?? '') === 'dmy')>Day / Month / Year</option><option value="mdy" @selected(($options['date_order'] ?? '') === 'mdy')>Month / Day / Year</option></select></label>
    <label>Employee matching<select name="employee_match"><option value="auto" @selected(($options['employee_match'] ?? 'auto') === 'auto')>Code, unique name or unique unpadded code</option><option value="code" @selected(($options['employee_match'] ?? '') === 'code')>Exact employee code only</option></select></label>
    @foreach(['employee_no' => 'Employee code or name', 'shift_date' => 'Attendance date', 'time_in' => 'Check-in', 'time_out' => 'Check-out', 'timestamp' => 'Punch timestamp', 'direction' => 'IN / OUT direction'] as $field => $label)
        <label @if(in_array($field, ['time_in','time_out'])) data-mapping-layout="daily" @elseif(in_array($field,['timestamp','direction'])) data-mapping-layout="punches" @endif>{{ $label }}<select name="mapping[{{ $field }}]" @if($field === 'employee_no') required @endif><option value="">Select a source column</option>@if(in_array($field,['shift_date','time_in','time_out']))<option value="-1" @selected(isset($mapping[$field]) && (int)$mapping[$field] === -1)>{{ $field === 'shift_date' ? 'Derive from full punch timestamps' : 'Not present — leave blank' }}</option>@endif @foreach($batch->headers as $i => $header)<option value="{{ $i }}" @selected(isset($mapping[$field]) && (int)$mapping[$field] === $i)>{{ $i+1 }} · {{ $header }}</option>@endforeach</select></label>
    @endforeach
    <div class="form-actions wide"><button class="btn primary">{{ $batch->status === 'mapped' ? 'Rebuild preview from spreadsheet' : 'Confirm mapping & preview' }}</button></div>
</form>
<p class="la-note">Suggestions are editable. Dates such as 03/04/2026 need an explicit date order unless other rows resolve it. Clock-only punches use the employee’s assigned shift, including overnight dates. Separate logs are grouped using earliest IN and latest OUT; numeric device status codes are flagged for review. Employee names must match uniquely. Review all interpretation notes before saving.</p>
<script src="{{ asset('attendance-mapping.js') }}" defer></script>
