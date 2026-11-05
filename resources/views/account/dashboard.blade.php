@extends('layouts.app')
@section('title','Employee Dashboard')
@section('content')
<div class="page-head"><div><h1>Employee Dashboard</h1><p>Welcome, {{ $employee->display_name }}.</p></div></div>
<section class="card"><h2>My workspace</h2><p><a href="{{ route('employees.show',$employee) }}">My Profile</a></p><p><a href="{{ route('la.register') }}">My Attendance</a></p><p><a href="{{ route('la.attendance.form') }}">Submit Attendance</a></p><p><a href="{{ route('la.corrections') }}">Attendance Request History</a></p><p><a href="{{ route('la.leave.form') }}">Apply for Leave</a></p><p><a href="{{ route('la.leaves') }}">Leave Request History</a></p><p><a href="{{ route('account.show') }}">My Account</a></p></section>
<section class="card"><h2>Company updates</h2>@forelse($news as $item)<article><h3>{{ $item->title }}</h3><p>{{ $item->body }}</p></article>@empty<p>No announcements published.</p>@endforelse</section>
@endsection
