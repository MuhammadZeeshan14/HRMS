<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EmployeeAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function form()
    {
        return view('auth.login');
    }

    public function login(Request $r)
    {
        $data = $r->validate(['login_id' => 'required|string|max:255', 'password' => 'required|string']);
        $identifier = trim($data['login_id']);
        $accounts = User::query();
        if (str_contains($identifier, '@')) {
            $email = strtolower($identifier);
            $accounts->where(function ($query) use ($email) {
                $query->whereRaw('LOWER(email) = ?', [$email])
                    ->orWhereHas('employee', fn ($employee) => $employee->whereRaw('LOWER(email) = ?', [$email]));
            });
        } else {
            $accounts->where('login_id', $identifier);
        }
        // Ambiguous imported email addresses must never select an arbitrary account.
        $matches = $accounts->limit(2)->get();
        if ($matches->count() !== 1 || ! Auth::attempt(['id' => $matches->first()->id, 'password' => $data['password']], $r->boolean('remember'))) {
            return back()->withErrors(['login_id' => 'Invalid credentials.'])->onlyInput('login_id');
        } $r->session()->regenerate();
        if (! app(EmployeeAccess::class)->enabled(Auth::user())) {
            Auth::logout();

            $r->session()->invalidate();

            return back()->withErrors(['login_id' => 'This account is inactive or is not linked to an active employee.']);
        }

        $r->session()->put('account_version', Auth::user()->session_version);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
