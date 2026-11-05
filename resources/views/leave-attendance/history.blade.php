@extends('layouts.app')
@section('title','Attendance versions')
@section('module-title','Attendance version history')
@section('content')
@include('leave-attendance.nav')
<div class="la-module">@include('leave-attendance.identity')<form class="form-grid master-form la-form"><label class="field-short">Shift date<input type="date" name="date" value="{{ request('date') }}"></label><button class="btn">Filter</button></form>
<section class="card master-table"><div class="master-list-scroll la-scroll" tabindex="0" role="region" aria-label="Attendance version history"><table class="master-records"><thead><tr><th>Version / Shift date</th><th>Shift snapshot</th><th>Original punches</th><th>Actor / committed</th><th>Source / provenance</th><th>Reason</th></tr></thead><tbody>
@forelse($records as $v)<tr><td>#{{ $v->id }}<br>{{ $v->shift_date }}</td><td>{{ $v->shift_name }}<br>{{ \App\Support\HrlaTime::stamp($v->shift_start) }} → {{ \App\Support\HrlaTime::stamp($v->shift_end) }}</td><td>{{ \App\Support\HrlaTime::stamp($v->time_in) }} → {{ \App\Support\HrlaTime::stamp($v->time_out) }}</td><td>{{ $v->actor?->name }}<br>{{ \App\Support\HrlaTime::stamp($v->committed_at) }}</td><td>{{ $v->source }}<br>@if($v->batch_id)Batch #{{ $v->batch_id }} · Row {{ $v->source_row }}@endif @if($v->correction_id)Request #{{ $v->correction_id }}<br>Approval: {{ $v->correction?->decision?->actor?->name }} · {{ \App\Support\HrlaTime::stamp($v->correction?->decision?->decided_at) }}@endif</td><td>{{ $v->reason }}<br>{{ $v->client_name }}</td></tr>@empty<tr><td colspan="6" class="empty">No committed versions. Pending requests never replace attendance.</td></tr>@endforelse
</tbody></table></div><div class="pager">{{ $records->links() }}</div></section></div>
@endsection
