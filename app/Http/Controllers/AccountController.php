<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\RecoveryVerification;
use App\Services\AccountService;
use App\Services\EmployeeAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function show(Request $r)
    {
        return view('account.show', ['employee' => $r->user()->employee()->with(['company', 'department', 'primaryReport', 'secondaryReport'])->firstOrFail(), 'access' => app(EmployeeAccess::class)]);
    }

    public function password(Request $r, AccountService $accounts)
    {
        $data = $r->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols(), 'different:current_password']]);
        $u = $r->user();
        DB::transaction(function () use (&$u, $data, $accounts) {
            $u = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $u->password)) {
                throw ValidationException::withMessages(['current_password' => 'Your password changed. Sign in again before changing it.']);
            }
            $u->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false])->save();
            $accounts->revoke($u);
            $this->audit($u, 'password.changed');
        });
        $r->session()->regenerate();
        $r->session()->put('account_version', $u->session_version);

        return redirect()->route('account.show')->with('success', 'Password changed. Other sessions have been revoked.');
    }

    public function profile(Request $r)
    {
        $data = $r->validate(['phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s().-]{7,30}$/'], 'address' => ['nullable', 'string', 'max:500'], 'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072']]);
        unset($data['photo']);
        if ($r->hasFile('photo')) {
            $data['photo_path'] = $r->file('photo')->store('employee-photos', 'public');
        }
        $r->user()->employee->update($data);

        return back()->with('success', 'Personal contact information updated.');
    }

    public function sessions(Request $r, AccountService $accounts)
    {
        $r->validate(['current_password' => ['required', 'current_password']]);
        $accounts->revoke($r->user());
        $r->session()->regenerate();
        $r->session()->put('account_version', $r->user()->session_version);

        return back()->with('success', 'Other sessions revoked.');
    }

    public function assisted(Request $r, Employee $employee, AccountService $accounts)
    {
        $r->validate(['current_password' => ['required', 'current_password']]);
        $temporary = $accounts->temporary($employee, $r->user());

        // Render once, never flash passwords into the session or logs.
        return response()->view('account.temporary', compact('employee', 'temporary'))->header('Cache-Control', 'no-store, private');
    }

    public function recovery(Request $r)
    {
        $data = $r->validate(['current_password' => ['required', 'current_password'], 'email' => ['required', 'email', 'max:255', 'unique:users,email']]);
        $u = $r->user();
        $u->forceFill(['pending_recovery_email' => $data['email']])->save();
        $url = URL::temporarySignedRoute('account.recovery.verify', now()->addMinutes(30), ['user' => $u->id, 'email_hash' => hash('sha256', $data['email'])]);
        Notification::route('mail', $data['email'])->notify(new RecoveryVerification($url));

        return back()->with('success', 'A verification link was sent to your new recovery email. Your current recovery email remains active until verification.');
    }

    public function verifyRecovery(Request $r)
    {
        $u = $r->user();
        abort_unless((int) $r->input('user') === $u->id && $u->pending_recovery_email && hash_equals(hash('sha256', $u->pending_recovery_email), (string) $r->input('email_hash')), 403);
        $u->forceFill(['email' => $u->pending_recovery_email, 'email_verified_at' => now(), 'pending_recovery_email' => null])->save();
        $this->audit($u, 'recovery.verified');

        return redirect()->route('account.show')->with('success', 'Recovery email verified.');
    }

    public function forgotForm()
    {
        return view('auth.recovery', ['reset' => false]);
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        $u = User::where('email', $r->input('email'))->whereNotNull('email_verified_at')->first();
        if ($u && app(EmployeeAccess::class)->enabled($u)) {
            Password::sendResetLink(['email' => $u->email]);
        }

        return back()->with('success', 'If this is a verified recovery email for an active employee, a reset link has been sent. Otherwise ask an Admin in your employee scope for an assisted reset.');
    }

    public function resetForm(Request $r, string $token)
    {
        return view('auth.recovery', ['reset' => true, 'token' => $token, 'email' => $r->input('email')]);
    }

    public function reset(Request $r, AccountService $accounts)
    {
        $data = $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols()]]);
        $u = User::where('email', $data['email'])->whereNotNull('email_verified_at')->first();
        if (! $u || ! app(EmployeeAccess::class)->enabled($u)) {
            return back()->withErrors(['email' => 'This reset link is invalid.']);
        }
        $status = Password::reset($data, function (User $user, string $password) use ($accounts) {
            DB::transaction(function () use ($user, $password, $accounts) {
                $user->forceFill(['password' => Hash::make($password), 'must_change_password' => false])->save();
                $accounts->revoke($user);
                $this->audit($user, 'password.recovered');
            });
        });

        return $status === Password::PASSWORD_RESET ? redirect()->route('login')->with('success', __($status)) : back()->withErrors(['email' => __($status)]);
    }

    public function notification(Request $r, string $id)
    {
        $n = $r->user()->notifications()->findOrFail($id);
        $n->markAsRead();

        return redirect()->route('la.corrections');
    }

    private function audit(User $u, string $action): void
    {
        DB::table('account_access_events')->insert(['employee_id' => $u->employee->id, 'actor_id' => $u->id, 'action' => $action, 'details' => null, 'created_at' => now()]);
    }
}
