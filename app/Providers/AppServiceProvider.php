<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Employee::observe(\App\Observers\EmployeeObserver::class);
        \App\Models\User::saving(function ($user) {
            if ($user->exists && $user->isDirty('is_active') && !$user->is_active && ($e = $user->employee)) {
                $reporting = app(\App\Services\ReportingService::class);
                foreach (\App\Models\Employee::where(fn ($q) => $q->where('primary_report_to_id', $e->id)->orWhere('secondary_report_to_id', $e->id))->get() as $child) {
                    if ($reporting->eligible($child, $e)) throw \Illuminate\Validation\ValidationException::withMessages(['is_active' => 'Assign eligible replacements for reporting employees before disabling this account.']);
                }
            }
        });
    }
}
