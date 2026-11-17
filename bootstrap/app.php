<?php

use App\Http\Middleware\EmployeeSession;
use App\Http\Middleware\RequireConfigurationAccess;
use App\Http\Middleware\RequireRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES') ?: [],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->alias(['role' => RequireRole::class, 'configuration' => RequireConfigurationAccess::class]);
        $middleware->appendToGroup('web', EmployeeSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
