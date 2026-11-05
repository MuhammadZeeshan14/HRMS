@extends('layouts.app')
@section('title','Employee Exit Workflow')
@section('content')
<div class="employee-breadcrumb"><a href="{{ route('employees.index') }}">Employee Directory</a> › Exit Workflow</div>
<div class="page-head"><div><h1>Employee Exit Workflow</h1><p>{{ $employee->display_name }} · {{ $employee->employee_no }}</p></div><a class="btn" href="{{ route('employees.index') }}">Back to Directory</a></div>
<form method="post" action="{{ route('employees.exit.update',$employee) }}">
@csrf @method('PUT')
@include('employees.partials.exit-records')
<div class="form-actions"><a class="btn" href="{{ route('employees.index') }}">Cancel</a><button class="btn primary">Save Exit Records</button></div>
</form>
@endsection
