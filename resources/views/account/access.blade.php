@extends('layouts.app')
@section('title','Admin Privileges')
@section('content')
<h1>Admin Privileges</h1><p>Only Level 1 employees can delegate access to Level 2 or Level 3 employees in their company. Reporting remains restricted to ancestor departments.</p>
@foreach($employees as $employee)
<section class="card"><strong>{{ $employee->display_name }} · {{ $employee->department?->name }} · {{ $access->source($employee) ?: 'Employee' }}</strong>
@if($employee->id !== auth()->user()->employee->id && in_array($access->level($employee),[2,3],true))
<form method="post" action="{{ route('access.update',$employee) }}">@csrf<input type="hidden" name="grant" value="{{ $employee->delegated_admin ? 0 : 1 }}"><label>Current password<input type="password" name="current_password" required></label><button class="btn">{{ $employee->delegated_admin ? 'Revoke delegated Admin' : 'Grant delegated Admin' }}</button></form>
@endif
</section>@endforeach
@endsection
