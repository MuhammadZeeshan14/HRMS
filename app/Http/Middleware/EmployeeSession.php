<?php

namespace App\Http\Middleware;

use App\Services\EmployeeAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmployeeSession
{
    public function handle(Request $r, Closure $next)
    {
        if ($u = $r->user()) {
            $u = $u->fresh();
            if (! $u) {
                Auth::logout();

                return redirect()->route('login');
            }
            Auth::setUser($u);
            if (! app(EmployeeAccess::class)->enabled($u) || ($r->session()->has('account_version') && (int) $r->session()->get('account_version') !== (int) $u->session_version)) {
                Auth::logout();
                $r->session()->invalidate();
                $r->session()->regenerateToken();

                return redirect()->route('login')->withErrors(['login_id' => 'Your account is inactive or this session was revoked.']);
            }
            $r->session()->put('account_version', $u->session_version);
        }

        return $next($r);
    }
}
