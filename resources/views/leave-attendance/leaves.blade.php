@extends('layouts.app')
@section('title','Leave inbox')
@section('module-title','Leave applications & approvals')
@section('content')
@include('leave-attendance.nav')
@include('leave-attendance.inbox-filters')
<div class="la-module"><p class="la-note">Only approved applications affect the register. Undefined eligibility, allowance period, or accounting rules block approval.</p><section class="card master-table"><div class="master-list-scroll la-scroll" tabindex="0" role="region" aria-label="Leave applications"><table class="master-records"><thead><tr><th>Employee</th><th>Application</th><th>Dates / days</th><th>Reason</th><th>Outcome</th><th>Decision</th></tr></thead><tbody>
@forelse($records as $record)<tr><td>{{ $record->employee->employee_no }} · {{ $record->employee->display_name }}<br>{{ $record->employee->department?->name }}</td><td>#{{ $record->id }} · {{ $record->leaveType->name }}<br>Form date: {{ $record->form_date }}</td><td>{{ $record->starts_on }} → {{ $record->ends_on }}<br>{{ $record->chargeable_days }} working days</td><td>{{ $record->reason }}</td><td>{{ ucfirst($record->status) }}</td><td>@include('leave-attendance.decision',['action'=>route('la.leaves.decide',$record)])</td></tr>@empty<tr><td colspan="6" class="empty">No leave applications in your authorized scope.</td></tr>@endforelse
</tbody></table></div><div class="pager">{{ $records->links() }}</div></section></div>
@endsection
