@extends('layouts.app')
@section('title','Temporary password')
@section('content')
<section class="card"><h1>Temporary password issued</h1><p>{{ $employee->display_name }} · {{ $employee->user->login_id }}</p><p>Share this password privately. It is shown only in this response.</p><p><code>{{ $temporary }}</code></p><p>The employee must change it at next login. Existing sessions have been revoked.</p></section>
@endsection
