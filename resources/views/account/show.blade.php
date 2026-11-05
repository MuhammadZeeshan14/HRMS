@extends('layouts.app')
@section('title','My Account')
@section('content')
<div class="page-head"><h1>My Account</h1></div>
@if(auth()->user()->must_change_password)<div class="alert danger">You are using a temporary password. You can continue to other pages and change it here when ready.</div>@endif
<section class="card"><h2>Employee account</h2><dl>
@foreach(['Login ID'=>auth()->user()->login_id,'Name'=>$employee->display_name,'Company'=>$employee->company?->name,'Department'=>$employee->department?->name,'Primary reporting person'=>$employee->primaryReport?->display_name,'Secondary reporting person'=>$employee->secondaryReport?->display_name,'Effective access'=>$access->admin(auth()->user()) ? 'Admin' : 'Employee','Admin access source'=>$access->source($employee),'Verified recovery email'=>auth()->user()->email_verified_at ? auth()->user()->email : 'No verified email. Ask an authorized Admin for an assisted reset.'] as $label=>$value)<dt>{{ $label }}</dt><dd>{{ $value ?: '—' }}</dd>@endforeach
</dl></section>
<section class="card"><h2>Change password</h2><form method="post" action="{{ route('account.password') }}">@csrf
<label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
<label>New password (12 characters, upper/lowercase, number and symbol)<input type="password" name="password" autocomplete="new-password" required minlength="12"></label>
<label>Confirm new password<input type="password" name="password_confirmation" autocomplete="new-password" required></label><button class="btn primary">Change password</button></form></section>
<section class="card"><h2>Personal contact and photo</h2><form method="post" enctype="multipart/form-data" action="{{ route('account.profile') }}">@csrf<label>Phone<input name="phone" value="{{ old('phone',$employee->phone) }}" maxlength="30"></label><label>Address<textarea name="address" maxlength="500">{{ old('address',$employee->address) }}</textarea></label><label>Profile photo<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label><button class="btn primary">Save personal information</button></form></section>
<section class="card"><h2>Recovery email</h2><form method="post" action="{{ route('account.recovery') }}">@csrf<label>New recovery email<input type="email" name="email" required></label><label>Current password<input type="password" name="current_password" required></label><button class="btn">Send verification link</button></form></section>
<section class="card"><h2>Other sessions</h2><form method="post" action="{{ route('account.sessions') }}">@csrf<label>Current password<input type="password" name="current_password" required></label><button class="btn">Revoke other sessions</button></form></section>
<section class="card"><h2>Notifications</h2>@forelse(auth()->user()->notifications()->latest()->limit(30)->get() as $notification)<p><a href="{{ route('account.notification',$notification->id) }}">{{ $notification->data['message'] ?? 'Attendance request' }}</a>{{ $notification->read_at ? ' · Read' : ' · New' }}</p>@empty<p>No notifications.</p>@endforelse</section>
@endsection
