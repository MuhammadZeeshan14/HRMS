<?php

use App\Http\Middleware\EmployeeSession;
use App\Http\Middleware\RequireConfigurationAccess;
use App\Http\Middleware\RequireRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['role' => RequireRole::class, 'configuration' => RequireConfigurationAccess::class]);
        $middleware->appendToGroup('web', EmployeeSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
