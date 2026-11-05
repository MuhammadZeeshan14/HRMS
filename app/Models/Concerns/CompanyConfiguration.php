<?php

namespace App\Models\Concerns;

use App\Services\EmployeeAccess;
use Illuminate\Database\Eloquent\Builder;

trait CompanyConfiguration
{
    protected static function bootCompanyConfiguration(): void
    {
        static::addGlobalScope('company', function (Builder $q) {
            if (auth()->check()) {
                $company = auth()->user()->employee()->value('company_id');
                $q->where(fn ($q) => $q->where($q->getModel()->getTable().'.company_id', $company)->orWhereNull($q->getModel()->getTable().'.company_id'));
            }
        });
        static::saving(function ($model) {
            if (! auth()->check()) {
                return;
            }
            $company = auth()->user()->employee()->value('company_id');
            abort_unless(app(EmployeeAccess::class)->admin(auth()->user()), 403);
            if (! $model->exists) {
                $model->company_id = $company;
            }
            abort_unless($company && (int) $model->company_id === (int) $company && (! $model->exists || (int) $model->getOriginal('company_id') === (int) $company), 403, 'Shared legacy configuration is read-only. Assign its company through the migration workflow.');
        });
        static::deleting(function ($model) {
            if (auth()->check()) {
                abort_unless(app(EmployeeAccess::class)->admin(auth()->user()) && (int) $model->company_id === (int) auth()->user()->employee()->value('company_id'), 403);
            }
        });
    }
}
