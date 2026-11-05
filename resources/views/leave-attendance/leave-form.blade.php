@extends('layouts.app')
@section('title','Leave application')
@section('module-title','Apply for leave')
@section('content')
@include('leave-attendance.nav')
<div class="la-module">
<form method="get" class="card form-grid master-form la-form"><label class="field-medium">Employee<select name="employee_id" required>@foreach($options['employees'] as $e)<option value="{{ $e->id }}" @selected($employee?->id===$e->id)>{{ $e->employee_no }} · {{ $e->display_name }}</option>@endforeach</select></label><div class="form-actions wide"><button class="btn">Load employee</button></div></form>
@if($employee)
@include('leave-attendance.identity')
<form method="post" action="{{ route('la.leave.store') }}" class="card form-grid master-form la-form" data-la-leave data-preview-url="{{ route('la.leave.preview') }}">
@csrf<input type="hidden" name="employee_id" value="{{ $employee->id }}">
<h2 class="shared-form-title wide">Leave application</h2>
<label class="field-short">Form Date<input type="date" name="form_date" value="{{ old('form_date',now()->toDateString()) }}" required></label>
<label class="field-medium">Leave Type<select name="leave_type_id" required><option value="">Select configured leave type</option>@foreach($types as $type)<option value="{{ $type->id }}" @selected(old('leave_type_id')==$type->id)>{{ $type->code }} · {{ $type->name }}</option>@endforeach</select></label>
<label class="field-short">Start Date<input type="date" name="starts_on" value="{{ old('starts_on') }}" required></label><label class="field-short">End Date<input type="date" name="ends_on" value="{{ old('ends_on') }}" required></label>
<label class="field-medium">Number of Days<output data-la-days aria-live="polite">Select dates to calculate</output></label><label class="field-medium">Half Day<input value="Inactive — session rules pending" readonly></label>
<label class="wide">Reason for Leave<textarea name="reason" required maxlength="2000">{{ old('reason') }}</textarea></label>
<p class="wide la-note">Every Saturday, Sunday and configured holiday contributes zero days. Ranges with no working days cannot be submitted. All applications, including HR submissions, require another authorized approver. Leave Type links this form to policies and balances.</p>
<div class="form-actions wide"><button class="btn primary" @disabled($types->isEmpty())>Submit for approval</button><a class="btn" href="{{ route('la.leaves') }}">Cancel</a>@if($types->isEmpty())<span>HR must configure leave types first.</span>@endif</div></form>
@else<p class="alert">No authorized employee is available.</p>@endif</div>
<script src="{{ asset('leave-attendance.js') }}" defer></script>
@endsection
