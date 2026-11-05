@extends('layouts.app')
@section('title','HRLA Dashboard')
@section('module-title','HRLA Dashboard')
@section('content')
@include('leave-attendance.nav')
<div class="la-module hrla-dashboard">
<p class="la-note">Attendance and leave activity for employees you can access. Today's figures use {{ $today }}.</p>
<div class="metric-grid">
@foreach(['employees'=>'Employees in scope','attendance'=>'Attendance recorded today','on_leave'=>'On approved leave today','pending_leaves'=>'Pending leave requests','pending_corrections'=>'Pending corrections'] as $key=>$label)
<div class="metric"><span>{{ $label }}</span><strong>{{ number_format($metrics[$key]) }}</strong><small>{{ in_array($key,['attendance','on_leave']) ? 'Today' : 'Your accessible records' }}</small></div>
@endforeach
</div>
<div class="hrla-link-grid">
@if(!app(\App\Services\EmployeeAccess::class)->admin(auth()->user()))
<a class="hrla-link-card" href="{{ route('la.employee.today') }}"><h2>Attendance</h2><p>Capture today's Check In / Check Out with your current location.</p><strong>Record a live punch →</strong></a>
<a class="hrla-link-card" href="{{ route('la.attendance.form') }}"><h2>Edit / Mark Attendance</h2><p>Request attendance or corrections for a previous day.</p><strong>Submit for approval →</strong></a>
<a class="hrla-link-card" href="{{ route('la.employee.history') }}"><h2>Attendance History</h2><p>View daily attendance, request status and original punches.</p><strong>View my records →</strong></a>
@else
<a class="hrla-link-card" href="{{ route('la.register') }}"><h2>Daily attendance</h2><p>Review punches, shifts, hours and attendance status.</p><strong>Open register →</strong></a>
@endif
<a class="hrla-link-card" href="{{ route('la.leaves') }}"><h2>Leave requests</h2><p>Review applications and follow approval decisions.</p><strong>Open leave inbox →</strong></a>
<a class="hrla-link-card" href="{{ route('la.balances') }}"><h2>Leave balances</h2><p>Check allowances and usage for each leave period.</p><strong>View balances →</strong></a>
@if(app(\App\Services\LeaveAttendance\Access::class)->hr(auth()->user()))
<a class="hrla-link-card" href="{{ route('la.import') }}"><h2>Attendance upload</h2><p>Upload a spreadsheet, map columns and verify editable attendance rows.</p><strong>Upload attendance →</strong></a>
<a class="hrla-link-card" href="{{ route('la.setup.overview') }}"><h2>HRLA Setup</h2><p>Configure shifts, assignments, holidays and leave policies.</p><strong>Manage setup →</strong></a>
@endif
</div>
<div class="grid-2">
<section class="card"><div class="card-head"><h2>Recent leave requests</h2><a href="{{ route('la.leaves') }}">View all</a></div><div class="master-list-scroll la-scroll"><table class="master-records"><thead><tr><th>Employee</th><th>Leave</th><th>Status</th></tr></thead><tbody>
@forelse($recentLeaves as $record)<tr><td>{{ $record->employee->display_name }}</td><td>{{ $record->leaveType->name }}<br>{{ $record->starts_on }} – {{ $record->ends_on }}</td><td>{{ ucfirst($record->status) }}</td></tr>@empty<tr><td colspan="3">No leave requests yet.</td></tr>@endforelse
</tbody></table></div></section>
<section class="card"><div class="card-head"><h2>Recent corrections</h2><a href="{{ route('la.corrections') }}">View all</a></div><div class="master-list-scroll la-scroll"><table class="master-records"><thead><tr><th>Employee</th><th>Date</th><th>Status</th></tr></thead><tbody>
@forelse($recentCorrections as $record)<tr><td>{{ $record->employee->display_name }}</td><td>{{ $record->shift_date }}</td><td>{{ ucfirst($record->status) }}</td></tr>@empty<tr><td colspan="3">No attendance corrections yet.</td></tr>@endforelse
</tbody></table></div></section>
</div></div>
@endsection
