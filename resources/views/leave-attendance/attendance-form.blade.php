@extends('layouts.app')
@section('title', $direct ? 'Record attendance punch' : 'Edit attendance punch')
@section('content')
<div class="la-module attendance-page">
    <div class="employee-breadcrumb"><a href="{{ route('la.dashboard') }}">HRLA</a> › <a href="{{ route('la.register') }}">Attendance</a> › <strong>{{ $direct ? 'Record punch' : 'Regularization request' }}</strong></div>
    <div class="page-head attendance-page-head">
        <div><span class="eyebrow">Employee attendance</span><div class="attendance-title"><h1>{{ $direct ? 'Record attendance punch' : 'Edit attendance punch' }}</h1><span class="attendance-tag">{{ $direct ? 'Direct entry' : 'Regularization request' }}</span></div><p>{{ $direct ? 'Review the employee and shift before recording attendance.' : 'Review the original punches and submit your correction for approval.' }}</p></div>
        <a class="btn" href="{{ route('la.register') }}"><x-icon name="list" /> Attendance logs</a>
    </div>
    <form method="get" class="card attendance-selector" action="{{ route('la.attendance.form') }}">
        @if($direct)<input type="hidden" name="direct" value="1"><label>Employee<select name="employee_id" required>@foreach($options['employees'] as $e)<option value="{{ $e->id }}" @selected($employee?->id === $e->id)>{{ $e->employee_no }} · {{ $e->display_name }}</option>@endforeach</select></label>@endif
        <label>Attendance date<input type="date" name="shift_date" value="{{ $date }}" required></label>
        <button class="btn" type="submit">Load attendance</button><span class="attendance-timezone">Times in {{ config('app.timezone') }}</span>
    </form>
    @if($employee)
    @php
        $initialIn = old('time_in', $current?->time_in ? str_replace(' ', 'T', $current->time_in) : '');
        $initialOut = old('time_out', $current?->time_out ? str_replace(' ', 'T', $current->time_out) : '');
        $initialIn = str_replace(' ', 'T', $initialIn ?? '');
        $initialOut = str_replace(' ', 'T', $initialOut ?? '');
    @endphp
    <div class="attendance-layout">
        <form method="post" action="{{ route('la.attendance.store') }}" class="attendance-editor" data-attendance-editor>
            @csrf
            <input type="hidden" name="direct" value="{{ $direct ? 1 : 0 }}"><input type="hidden" name="employee_id" value="{{ $employee->id }}"><input type="hidden" name="shift_date" value="{{ $date }}"><input type="hidden" name="base_version_id" value="{{ $current?->id }}">
            <section class="card attendance-punch-card">
                <div class="card-head"><h2><x-icon name="edit" /> {{ $direct ? 'Record attendance punch' : 'Punch details' }}</h2><span class="attendance-state" data-attendance-state>{{ $direct ? 'Manual entry' : 'Pending submission' }}</span></div>
                <div class="attendance-section-title"><span></span><h3>Attendance details</h3></div>
                <div class="form-grid master-form attendance-fields">
                    <label>Attendance date<input value="{{ \Carbon\Carbon::parse($date)->format('d M Y, l') }}" readonly></label>
                    <label>Calculated duration<output data-attendance-duration aria-live="polite">Enter both punches to calculate</output><small>Time between the entered punches</small></label>
                    <div class="attendance-punch-input"><x-hrla-datetime name="time_in" :value="$initialIn" :default-date="now()->toDateString()" label="Check-in" /><small id="original-time-in">Original: <strong>{{ \App\Support\HrlaTime::stamp($current?->time_in,'No punch recorded') }}</strong></small></div>
                    <div class="attendance-punch-input"><x-hrla-datetime name="time_out" :value="$initialOut" :default-date="now()->toDateString()" label="Check-out" /><small id="original-time-out">Original: <strong>{{ \App\Support\HrlaTime::stamp($current?->time_out,'No punch recorded') }}</strong></small></div>
                </div>
                <p class="la-note">Use the actual calendar date for each punch, including next-day checkout for overnight shifts. Blank punches stay blank.</p>
                <div class="attendance-section-title"><span></span><h3>{{ $direct ? 'Entry details' : 'Reason for regularization' }}</h3></div>
                <div class="form-grid master-form attendance-fields">
                    <label>Reason type <span class="attendance-required">*</span><select name="reason_type" required><option value="">Select reason</option>@foreach(['missed_punch'=>'Missed punch','client_meeting'=>'Client meeting','correction'=>'Correction','other'=>'Other'] as $key=>$label)<option value="{{ $key }}" @selected(old('reason_type') === $key)>{{ $label }}</option>@endforeach</select></label>
                    <label>Client name<input name="client_name" value="{{ old('client_name') }}" maxlength="255"><small>Required for a client meeting.</small></label>
                    <label class="wide">Remarks &amp; justification <span class="attendance-required" data-remarks-required @if(old('reason_type') !== 'other') hidden @endif>*</span><textarea name="reason" @required(old('reason_type') === 'other') maxlength="2000" placeholder="Explain the attendance entry or correction…" aria-describedby="attendance-remarks-help">{{ old('reason') }}</textarea><small id="attendance-remarks-help">Optional unless the reason type is Other.</small></label>
                </div>
            </section>
            <div class="card attendance-savebar">
                <span class="attendance-save-state" data-attendance-save-state><i></i> {{ $direct ? 'Saves a new attendance version' : 'Requires approval before attendance changes' }}</span>
                <div><a class="btn" href="{{ route('la.register') }}">Cancel</a><button class="btn primary" type="submit"><x-icon name="check" /> {{ $direct ? 'Save attendance punch' : 'Save & submit' }}</button></div>
            </div>
        </form>
        <aside class="attendance-context" aria-label="Employee and shift details">
            <section class="card attendance-profile">
                <div class="attendance-profile-head"><span class="attendance-avatar">{{ strtoupper(mb_substr($employee->first_name,0,1).mb_substr($employee->last_name ?? '',0,1)) }}</span><div><h2>{{ $employee->display_name }}</h2><p>{{ $employee->designation?->name ?? 'Designation not assigned' }}</p><span class="attendance-employee-code">{{ $employee->employee_no }}</span></div></div>
                <dl class="attendance-profile-details"><div><dt>Department</dt><dd>{{ $employee->department?->name ?? 'Not assigned' }}</dd></div><div><dt>Shift roster</dt><dd>{{ $schedule['shift_name'] ?? 'Not assigned' }}</dd></div><div><dt>Reports to</dt><dd>{{ $employee->primaryReport?->display_name ?? 'Not assigned' }}</dd></div></dl>
            </section>
            <section class="card attendance-location">
                <div class="card-head"><h2><x-icon name="pin" /> Work location</h2><span class="attendance-state">Employee profile</span></div>
                <div class="attendance-location-visual"><span><x-icon name="pin" /></span><strong>{{ $employee->location?->name ?? 'No location assigned' }}</strong></div>
                <dl class="attendance-profile-details"><div><dt>Entry method</dt><dd>{{ $direct ? 'Manual entry' : 'Correction request' }}</dd></div><div><dt>Shift starts</dt><dd>{{ \App\Support\HrlaTime::stamp($schedule['shift_start'] ?? null,'Not configured') }}</dd></div><div><dt>Shift ends</dt><dd>{{ \App\Support\HrlaTime::stamp($schedule['shift_end'] ?? null,'Not configured') }}</dd></div></dl>
                <p class="la-note">{{ $direct ? 'Recorded by '.auth()->user()->name : 'Your correction is sent to authorized HR users and supervisors for review.' }}</p>
            </section>
        </aside>
    </div>
    <section class="card master-table attendance-recent">
        <div class="card-head"><div><h2><x-icon name="list" /> Recent attendance logs</h2><p>Seven days ending {{ \Carbon\Carbon::parse($date)->format('d M Y') }} · {{ $employee->employee_no }}</p></div><a class="btn" href="{{ route('la.history', $employee) }}">View version history →</a></div>
        <div class="master-list-scroll" tabindex="0" role="region" aria-label="Recent employee attendance"><table class="master-records"><thead><tr><th>Date</th><th>Check-in</th><th>Check-out</th><th>Logged duration</th><th>Shift</th><th>Status</th><th>Source</th></tr></thead><tbody>
        @forelse($recentRows as $row)<tr><td>{{ \Carbon\Carbon::parse($row['date'])->format('D, d M Y') }}</td><td><span class="attendance-time-in">{{ \App\Support\HrlaTime::stamp($row['version']?->time_in) }}</span></td><td>{{ \App\Support\HrlaTime::stamp($row['version']?->time_out) }}</td><td><strong>{{ \App\Services\LeaveAttendance\AttendanceCalculator::duration($row['work_seconds']) }}</strong></td><td>{{ $row['schedule']['shift_name'] ?? 'Unassigned' }}</td><td><span class="master-status attendance-status" data-status="{{ $row['status'] }}">{{ $row['status'] }}</span></td><td>{{ $row['version']?->source ?? 'No recorded punch' }}</td></tr>@empty<tr><td colspan="7" class="empty">No attendance dates available for this employee.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    @else
    <p class="alert">No authorized employees are available for direct entry.</p>
    @endif
</div>
<script src="{{ asset('attendance-editor.js') }}" defer></script>
@endsection
