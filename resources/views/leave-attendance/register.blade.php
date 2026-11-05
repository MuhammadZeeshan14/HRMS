@extends('layouts.app')
@section('title','Daily attendance register')
@section('module-title','Attendance logs')
@section('content')
@include('leave-attendance.nav')
<div class="la-module attendance-page">
<form method="get" class="card form-grid master-form la-form">
<div class="card-head wide"><h2><x-icon name="search" /> Filter attendance logs</h2><span class="attendance-state">{{ config('app.timezone') }}</span></div>
<label class="field-short">From<input type="date" name="from" value="{{ $from }}" required></label><label class="field-short">To<input type="date" name="to" value="{{ $to }}" required></label>
@foreach(['company_id'=>['Company',$options['companies']],'department_id'=>['Department',$options['departments']],'employee_id'=>['Employee',$options['employees']]] as $field=>[$label,$choices])
@include('leave-attendance.register-multiselect', ['items' => $choices->map(fn($choice) => ['value' => $choice->id, 'label' => $field === 'employee_id' ? $choice->employee_no.' · '.$choice->display_name : $choice->name])->all()])
@endforeach
@include('leave-attendance.register-multiselect', ['field' => 'status', 'label' => 'Day Status', 'items' => collect(\App\Services\LeaveAttendance\AttendanceCalculator::STATUSES)->map(fn($status) => ['value' => $status, 'label' => $status])->all()])
<div class="form-actions wide"><button class="btn primary">Apply filters</button><button class="btn" name="export" value="1">Export CSV</button><a class="btn" href="{{ route('la.register') }}">Reset</a></div>
</form>
<p class="la-note">Work Hours and Diff use original punches. Actual Hours means scheduled duration. Days off take precedence over leave. Dates without punches remain visible.</p>
<section class="card master-table attendance-recent attendance-register"><div class="card-head"><div><h2><x-icon name="list" /> Attendance records</h2><p>{{ number_format($records->total()) }} employee dates · {{ $from }} to {{ $to }}</p></div>@if(auth()->user()->employee)<a class="btn" href="{{ route('la.attendance.form') }}"><x-icon name="edit" /> My attendance correction</a>@endif</div><div class="master-list-scroll la-scroll" role="region" aria-label="Daily attendance register" tabindex="0"><table class="master-records"><thead><tr>@foreach(['Code / Name','Department','Date','Shift','Shift Start','Shift End','Time In','Time Out','Day Status','Work Hours','Actual Hours','Diff','Upload Date','Uploaded By','Actions'] as $label)<th @class(['master-actions-column' => $label === 'Actions'])>{{ $label }}</th>@endforeach</tr></thead><tbody>
@forelse($records as $row)
@php($v=$row['version']) @php($s=$row['schedule'])
<tr><td><strong>{{ $row['employee']->employee_no }}</strong><br>{{ $row['employee']->display_name }}</td><td>{{ $row['employee']->department?->name ?? '—' }}</td><td>{{ $row['date'] }}</td><td>{{ $s['shift_name'] ?? 'Unassigned' }}</td><td class="attendance-punch-clock" title="{{ \App\Support\HrlaTime::stamp($s['shift_start'] ?? null) }}">{{ \App\Support\HrlaTime::clock($s['shift_start'] ?? null) }}</td><td class="attendance-punch-clock" title="{{ \App\Support\HrlaTime::stamp($s['shift_end'] ?? null) }}">{{ \App\Support\HrlaTime::clock($s['shift_end'] ?? null) }}</td><td class="attendance-punch-clock" title="{{ \App\Support\HrlaTime::stamp($v?->time_in) }}">{{ \App\Support\HrlaTime::clock($v?->time_in) }}</td><td class="attendance-punch-clock" title="{{ \App\Support\HrlaTime::stamp($v?->time_out) }}">{{ \App\Support\HrlaTime::clock($v?->time_out) }}</td>
<td><span class="master-status attendance-status" data-status="{{ $row['status'] }}">{{ $row['status'] }}</span></td>
@foreach(['work_seconds','actual_seconds','diff_seconds'] as $duration)<td>{{ \App\Services\LeaveAttendance\AttendanceCalculator::duration($row[$duration]) }}</td>@endforeach
<td>{{ \App\Support\HrlaTime::stamp($v?->committed_at) }}</td><td>{{ $v?->actor?->name ?? '—' }}</td><td class="master-actions-column"><div class="master-row-actions"><a class="btn" href="{{ route('la.history',[$row['employee'],'date'=>$row['date']]) }}">History</a>@if(auth()->user()->employee?->id === $row['employee']->id)<a class="btn" href="{{ route('la.attendance.form',['shift_date'=>$row['date']]) }}">Correct</a>@elseif(app(\App\Policies\LeaveAttendancePolicy::class)->manage(auth()->user(),$row['employee']))<a class="btn" href="{{ route('la.attendance.form',['direct'=>1,'employee_id'=>$row['employee']->id,'shift_date'=>$row['date']]) }}">Edit</a>@endif</div></td></tr>
@empty<tr><td colspan="15" class="empty">No employee dates match these filters. Configure shifts through Setup if needed.</td></tr>@endforelse
</tbody></table></div><div class="pager">{{ $records->links() }}</div></section></div>
<script src="{{ asset('attendance-filters.js') }}" defer></script>
@endsection
