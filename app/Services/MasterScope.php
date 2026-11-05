<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MasterScope
{
    public function query(string $table)
    {
        $q = DB::table($table);
        $u = auth()->user();
        $access = app(EmployeeAccess::class);
        $e = $access->employee($u);
        abort_unless($e && $access->admin($u), 403);
        if ($table === 'companies') {
            $q->where('id', $e->company_id);
        } elseif ($table === 'departments') {
            $q->whereIn('id', $access->departments($u));
        } elseif ($table === 'news') {
            $q->whereExists(fn ($x) => $x->selectRaw('1')->from('news_companies')->whereColumn('news_id', 'news.id')->where('company_id', $e->company_id));
        } elseif (Schema::hasColumn($table, 'company_id')) {
            $q->where(fn ($x) => $x->where('company_id', $e->company_id)->orWhereNull('company_id'));
        }

        return $q;
    }

    public function write(string $table, array $data, ?int $id): void
    {
        $u = auth()->user();
        $access = app(EmployeeAccess::class);
        $e = $access->employee($u);
        abort_unless($e && $access->admin($u), 403);
        $record = $id ? $this->query($table)->find($id) : null;
        abort_if($id && ! $record, 403);
        if ($table === 'departments') {
            // These fields determine automatic Admin eligibility and reporting ancestry.
            $changesAuthority = ! $id;
            foreach (['parent_department_id', 'is_hr', 'status'] as $field) {
                if (array_key_exists($field, $data) && (string) $data[$field] !== (string) ($record->$field ?? '')) {
                    $changesAuthority = true;
                }
            }
            abort_unless(! $changesAuthority || $access->levelOne($u), 403, 'Department creation and changes to hierarchy, HR designation or activation require a Level 1 employee because they affect Admin eligibility and reporting assignments.');
        }
        if ($table === 'companies') {
            abort_unless($id && (int) $id === (int) $e->company_id, 403);
        } elseif (Schema::hasColumn($table, 'company_id')) {
            if ($id) {
                abort_unless((int) $record->company_id === (int) $e->company_id, 403, 'Shared legacy records are read-only.');
            }
            abort_unless((int) ($data['company_id'] ?? $record?->company_id) === (int) $e->company_id, 403, 'Choose a record owned by your company. Shared legacy records are read-only.');
        }
        foreach ($data as $field => $value) {
            if ($field === 'company_ids') {
                abort_unless(array_diff(array_map('intval', $value ?? []), [(int) $e->company_id]) === [], 403);

                continue;
            }
            $meta = config('hris_setup.'.$table.'.fields.'.$field);
            if ($value && isset($meta['table']) && ($meta['type'] ?? '') === 'relation') {
                abort_unless($this->query($meta['table'])->where('id', $value)->exists(), 403);
            }
        }
        if ($table === 'news' && $id) {
            abort_if(DB::table('news_companies')->where('news_id', $id)->where('company_id', '!=', $e->company_id)->exists(), 403, 'This announcement is shared with another company.');
        }
    }
}
