<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Password recovery</title><link rel="stylesheet" href="{{ asset('hrms.css') }}"></head><body><main class="login-card"><h1>{{ $reset ? 'Reset password' : 'Forgot Password' }}</h1>
@if(session('success'))<p>{{ session('success') }}</p>@endif
@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
<form method="post" action="{{ route($reset ? 'password.update' : 'password.email') }}">@csrf
<label>Verified recovery email<input type="email" name="email" value="{{ $email ?? old('email') }}" required></label>
@if($reset)<input type="hidden" name="token" value="{{ $token }}"><label>New password (12 characters with upper/lowercase, number and symbol)<input type="password" name="password" required></label><label>Confirm password<input type="password" name="password_confirmation" required></label>@endif
<button class="btn primary">{{ $reset ? 'Reset password' : 'Send reset link' }}</button></form>
<p>If you have no verified recovery email, ask an Admin authorized to manage your employee record for a temporary password.</p><a href="{{ route('login') }}">Return to sign in</a></main></body></html>
