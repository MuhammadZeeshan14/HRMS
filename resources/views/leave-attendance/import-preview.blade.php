@extends('layouts.app')
@section('title','Verify attendance upload')
@section('module-title','Verify attendance upload')
@section('content')
@include('leave-attendance.nav')
@php($committed = $batch->status === 'committed')
@php($unmapped = !in_array($batch->status, ['mapped', 'committed'], true))
<div class="la-module attendance-import">
    <ol class="import-steps" aria-label="Attendance upload steps"><li class="done"><b>1</b> Upload spreadsheet</li><li class="{{ $unmapped ? 'active' : 'done' }}"><b>2</b> Map columns</li><li class="{{ !$unmapped ? 'active' : '' }}"><b>3</b> Verify &amp; save</li></ol>
    <div class="import-file-summary"><div><strong>{{ $batch->filename }}</strong><span>Upload #{{ $batch->id }} · {{ \App\Support\HrlaTime::stamp($batch->created_at) }}</span></div><a class="btn" href="{{ route('la.import') }}">Back to uploads</a></div>
    @if(!$committed)
        <details class="card import-mapping" @if($batch->status !== 'mapped') open @endif>
            <summary>Map spreadsheet columns @if($batch->status === 'mapped')<small>Rebuilding the preview resets reviewed edits to the original spreadsheet values.</small>@endif</summary>
            @include('leave-attendance.import-mapping')
        </details>
    @endif
    @if($unmapped)
        <section class="card master-table"><div class="card-head"><h2>Spreadsheet sample</h2><span>First 25 rows · {{ count($batch->rows) }} rows in file</span></div><div class="master-list-scroll"><table class="master-records"><thead><tr><th>File row</th>@foreach($batch->headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>@foreach(array_slice($batch->rows,0,25) as $source)<tr><td>{{ $source['row'] }}</td>@foreach($batch->headers as $index => $header)<td>{{ str($source['cells'][$index] ?? '')->limit(160) }}</td>@endforeach</tr>@endforeach</tbody></table></div></section>
    @else
        @if($committed)<p class="alert success">Imported {{ $batch->results['imported'] }} · Skipped {{ $batch->results['skipped'] }} · Failed {{ $batch->results['failed'] }} · Saved {{ \App\Support\HrlaTime::stamp($batch->committed_at) }}</p>@endif
        <section class="card master-table import-review" data-import-review>
            <div class="card-head"><h2>{{ $committed ? 'Saved attendance' : 'Attendance database preview' }}</h2><span>{{ config('app.timezone') }} · 12-hour time</span></div>
            <div class="import-counts">@foreach(['total' => 'Total rows', 'valid' => $committed ? 'Saved rows' : 'Ready to save', 'invalid' => 'Rows with errors', 'excluded' => 'Excluded'] as $key => $label)<div><span>{{ $label }}</span><strong>{{ $summary[$key] }}</strong></div>@endforeach</div>
            <form method="get" class="import-filter"><label>Show rows<select name="filter">@foreach(['all' => 'All rows', 'invalid' => 'Rows with errors', 'valid' => $committed ? 'Saved rows' : 'Ready to save', 'excluded' => 'Excluded rows'] as $key => $label)<option value="{{ $key }}" @selected($filter === $key)>{{ $label }}</option>@endforeach</select></label><button class="btn">Filter</button><span>Showing {{ $reviewRows->firstItem() ?? 0 }}–{{ $reviewRows->lastItem() ?? 0 }} of {{ $reviewRows->total() }}</span></form>
            @if(!$committed)
                <p class="la-note">Mapped attendance preview. Double-click a cell or press Enter to edit. Paste ranges or use column tools to move data. “+1d” means the following day. Validate before saving.</p>
                <form id="import-review-form" method="post" action="{{ route('la.import.review',$batch) }}" data-import-review-form novalidate>
                    @csrf<input type="hidden" name="revision" value="{{ $revision }}"><input type="hidden" name="changes" value="[]"><input type="hidden" name="page" value="{{ $reviewRows->currentPage() }}"><input type="hidden" name="filter" value="{{ $filter }}">
                    <div class="import-review-actions"><button type="button" class="btn" data-include-page>Include this page</button><button type="button" class="btn" data-exclude-page>Exclude this page</button><button class="btn primary" data-validate-import disabled>Validate changes</button><span data-import-dirty aria-live="polite">Preview is validated.</span></div>
                    <div class="import-format-actions"><label>Date order<select name="date_order" data-fix-date-order><option value="auto" @selected(($mapping['_options']['date_order'] ?? 'auto') === 'auto')>Detect automatically</option><option value="dmy" @selected(($mapping['_options']['date_order'] ?? '') === 'dmy')>Day / Month / Year</option><option value="mdy" @selected(($mapping['_options']['date_order'] ?? '') === 'mdy')>Month / Day / Year</option></select></label><button type="submit" class="btn" name="auto_fix" value="1" data-auto-fix-import>Fix formats &amp; validate</button><small>Checks every row without resetting your edits. Choose a date order once if dates such as 03/04/2026 are ambiguous.</small></div>
                    @include('leave-attendance.import-sheet-toolbar')
            @endif
            <div class="master-list-scroll import-review-scroll" role="region" aria-label="Editable attendance verification table" tabindex="0">
                <table class="master-records import-review-table import-spreadsheet sheet-excel"><thead><tr class="sheet-column-letters"><th>@if(!$committed)<input type="checkbox" data-import-select-page aria-label="Include all rows on this page">@endif</th><th>A</th><th>B</th><th>C</th><th>D</th><th class="sheet-meta-column"></th><th class="sheet-meta-column"></th><th class="sheet-meta-column"></th></tr><tr class="sheet-field-headers"><th>1</th><th>Name</th><th>Date</th><th>Clock In</th><th>Clock Out</th><th class="sheet-meta-column">Shift</th><th class="sheet-meta-column">Validation</th><th class="sheet-meta-column">Hours</th></tr></thead><tbody>
                @forelse($reviewRows as $row)
                    @php($excluded = !empty($row['excluded']))
                    <tr data-import-row="{{ $row['row'] }}" data-sheet-row="{{ ($reviewRows->firstItem() ?? 1) + $loop->index + 1 }}" data-source-count="{{ count($row['source_rows'] ?? [$row['row']]) }}" class="{{ $excluded ? 'import-excluded' : (empty($row['errors']) ? '' : 'import-invalid') }}">
                        <td title="Original file row {{ $row['row'] }}"><strong>{{ ($reviewRows->firstItem() ?? 1) + $loop->index + 1 }}</strong>@if(!$committed)<label class="import-include"><input type="checkbox" data-import-include @checked(!$excluded) aria-label="Include File row {{ $row['row'] }}"></label>@endif</td>
                        <td data-grid-cell="employee_no" tabindex="0">@if($committed){{ $row['employee_name'] ?? $row['data']['employee_no'] }}@else<input data-import-field="employee_no" value="{{ $row['data']['employee_no'] }}" list="import-employee-codes" aria-label="Employee number, File row {{ $row['row'] }}" autocomplete="off">@endif<div data-import-identity><strong>{{ $row['employee_name'] ?? $employees->firstWhere('id', $row['employee_id'] ?? null)?->display_name ?? 'Employee not resolved' }}</strong><small>@if(!empty($row['employee_id']))Employee ID: {{ $row['employee_id'] }} · @endif{{ $row['department'] ?? '' }}</small></div></td>
                        <td data-grid-cell="shift_date" tabindex="0">@if($committed){{ $row['data']['shift_date'] }}@else<input type="date" data-import-field="shift_date" data-original-value="{{ $row['data']['shift_date'] }}" value="{{ $row['data']['shift_date'] }}" aria-label="Attendance date, File row {{ $row['row'] }}"><small data-sheet-date-warning></small>@endif</td>
                        @foreach(['time_in' => 'Check-in', 'time_out' => 'Check-out'] as $field => $label)
                            <td data-grid-cell="{{ $field }}" tabindex="0" title="{{ \App\Support\HrlaTime::stamp($row['data'][$field]) }}">@if($committed){{ \App\Support\HrlaTime::clock($row['data'][$field]) }}@else @include('leave-attendance.import-punch') @endif</td>
                        @endforeach
                        <td class="sheet-meta-column"><div data-import-shift><strong>{{ $row['schedule']['shift_name'] ?? (array_key_exists('schedule', $row) ? 'Unassigned' : 'Validate to confirm shift') }}</strong>@if(!empty($row['schedule']))<small>{{ \App\Support\HrlaTime::stamp($row['schedule']['shift_start']) }}<br>→ {{ \App\Support\HrlaTime::stamp($row['schedule']['shift_end']) }}</small>@endif</div></td>
                        <td class="import-validation-cell sheet-meta-column">@include('leave-attendance.import-validation')</td>
                        <td class="sheet-meta-column"><strong data-import-duration>{{ \App\Services\LeaveAttendance\AttendanceCalculator::duration($row['calculation']['work_seconds'] ?? null) }}</strong><small>Scheduled: {{ \App\Services\LeaveAttendance\AttendanceCalculator::duration($row['calculation']['actual_seconds'] ?? null) }}</small></td>
                    </tr>
                @empty<tr><td colspan="5" class="empty">No rows match this filter.</td></tr>@endforelse
                @for($blank=0;$blank<max(0,12-$reviewRows->count());$blank++)<tr class="sheet-empty-row" aria-hidden="true"><td>{{ ($reviewRows->firstItem() ?? 1)+$reviewRows->count()+$blank+1 }}</td><td></td><td></td><td></td><td></td></tr>@endfor
                </tbody></table>
            </div>
            @if(!$committed)<div class="sheet-inspector" data-sheet-inspector><span>Select a cell to see its employee, validation and source details.</span></div>@endif
            @if(!$committed)</form><datalist id="import-employee-codes">@foreach($employees as $employee)<option value="{{ $employee->employee_no }}">{{ $employee->display_name }}</option>@endforeach</datalist>@endif
            <div class="pager">{{ $reviewRows->links() }}</div>
            @if(!$committed)
                <form method="post" action="{{ route('la.import.commit',$batch) }}" class="import-savebar" data-import-commit>
                    @csrf<input type="hidden" name="revision" value="{{ $revision }}">
                    <div><strong>{{ $summary['valid'] }} verified rows will be saved.</strong><p>{{ $summary['invalid'] }} rows with errors and {{ $summary['excluded'] }} excluded rows will be skipped. Attendance changed since preview is reported as failed.</p></div>
                    <button class="btn primary" data-save-import @disabled($summary['valid'] === 0)>Save {{ $summary['valid'] }} verified rows</button>
                </form>
                <noscript><p class="alert">Enable JavaScript to edit and validate attendance rows before saving.</p><style>[data-import-commit]{display:none}</style></noscript>
            @else
                <div class="form-actions"><a class="btn primary" href="{{ route('la.register') }}">Open attendance register</a><a class="btn" href="{{ route('la.import') }}">Upload another file</a></div>
            @endif
        </section>
    @endif
</div>
<script src="{{ asset('attendance-import.js') }}" defer></script>
<script src="{{ asset('attendance-sheet.js') }}" defer></script>
@endsection
