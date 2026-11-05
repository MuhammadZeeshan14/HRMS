@extends('layouts.app')
@section('title','Correction inbox')
@section('module-title','Correction requests & history')
@section('content')
@include('leave-attendance.nav')
@include('leave-attendance.inbox-filters')
<div class="la-module"><section class="card master-table"><div class="master-list-scroll la-scroll" tabindex="0" role="region" aria-label="Correction requests"><table class="master-records"><thead><tr><th>Employee</th><th>Shift date</th><th>Proposed punches</th><th>Reason</th><th>Outcome</th><th>Decision</th></tr></thead><tbody>
@forelse($records as $record)<tr><td>{{ $record->employee->employee_no }} · {{ $record->employee->display_name }}<br>{{ $record->employee->department?->name }}</td><td>{{ $record->shift_date }}<br>Base version: {{ $record->base_version_id ?? 'none' }}</td><td>In: {{ \App\Support\HrlaTime::stamp($record->time_in) }}<br>Out: {{ \App\Support\HrlaTime::stamp($record->time_out) }}</td><td>{{ str($record->reason_type)->replace('_',' ') }}<br>{{ $record->reason }}<br>{{ $record->client_name }}</td><td>{{ ucfirst($record->status) }}</td><td>@include('leave-attendance.decision',['action'=>route('la.corrections.decide',$record)])</td></tr>@empty<tr><td colspan="6" class="empty">No correction requests in your authorized scope.</td></tr>@endforelse
</tbody></table></div><div class="pager">{{ $records->links() }}</div></section></div>
@endsection
