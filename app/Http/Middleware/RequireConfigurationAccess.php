<?php

namespace App\Http\Middleware;

use App\Services\EmployeeAccess;
use Closure;
use Illuminate\Http\Request;

class RequireConfigurationAccess
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless($r->user() && app(EmployeeAccess::class)->configure($r->user()), 403);

        return $next($r);
    }
}
