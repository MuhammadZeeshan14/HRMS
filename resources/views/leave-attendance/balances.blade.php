@extends('layouts.app')
@section('title','Leave balances')
@section('module-title','Leave balances')
@section('content')
@include('leave-attendance.nav')
<div class="la-module"><form method="get" class="form-grid master-form la-form card"><label class="field-medium">Employee<select name="employee_id">@foreach($options['employees'] as $e)<option value="{{ $e->id }}" @selected($employee?->id===$e->id)>{{ $e->employee_no }} · {{ $e->display_name }}</option>@endforeach</select></label><label class="field-short">Balance year<input type="number" name="balance_year" min="1900" max="9999" value="{{ $balanceYear }}" required></label><div class="form-actions wide"><button class="btn">View balance</button></div></form>
@include('leave-attendance.identity')
<p>Employee status: {{ $employee?->employeeStatus?->name ?? 'Not set in HRIS' }}. Calendar year {{ $balanceYear }} (January–December). Approved leave is deducted automatically; allowances reset each January. Carry-forward remains inactive.</p>
<section class="card master-table"><div class="master-list-scroll la-scroll"><table class="master-records"><thead><tr><th>Leave Type</th><th>Annual allowance</th><th>Leaves taken</th><th>Remaining</th><th>Setup status</th></tr></thead><tbody>
@forelse($balances as $b)<tr><td>{{ $b['type']->code }} · {{ $b['type']->name }}</td><td>{{ $b['policy']?->allowance_kind ?? 'Unconfigured' }}@if($b['policy']?->allowance_kind==='numeric') · {{ $b['policy']->allowance }}@endif</td><td>{{ $b['used'] ?? '—' }}</td><td>{{ !$b['error'] ? number_format((float)$b['policy']->allowance-$b['used'],2) : '—' }}</td><td>{{ $b['error'] ?? 'Configured' }}</td></tr>@empty<tr><td colspan="5" class="empty">No configured leave types.</td></tr>@endforelse
</tbody></table></div></section></div>
@endsection
