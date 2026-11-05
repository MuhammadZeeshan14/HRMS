<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Inserts new records only. Source keys never replace existing application codes. */
class AdditiveSetupImporter
{
    private array $rows = [];
    private array $ids = [];
    private array $visiting = [];
    private array $report = [];
    private array $overrides = [];

    public function run(array $payload, array $overrides = [], bool $commit = false): array
    {
        $this->rows = $this->ids = $this->visiting = [];
        $this->overrides = $overrides;
        $this->report = ['source' => $payload['source'], 'sha256' => $payload['sha256'], 'committed' => $commit,
            'summary' => [], 'mapping' => [], 'differences' => [], 'issues' => $payload['issues'] ?? [],
            'skipped_rows' => $payload['skipped_rows'] ?? [], 'unsupported_sheets' => $payload['unsupported_sheets'] ?? []];
        foreach ($payload['tables'] as $table => $rows) {
            if (!array_key_exists($table, config('hris_setup'))) throw new RuntimeException('Unsupported table: '.$table);
            foreach ($rows as $row) {
                if (isset($this->rows[$table][$row['key']])) throw new RuntimeException('Duplicate source key: '.$table.':'.$row['key']);
                $this->rows[$table][$row['key']] = $row;
            }
        }
        DB::beginTransaction();
        try {
            // Verify every existing master record, pivot, employee and user byte-for-byte.
            $before = [];
            foreach (array_merge(array_keys(config('hris_setup')), ['news_companies', 'employees', 'users']) as $table) {
                $before[$table] = DB::table($table)->lockForUpdate()->get()->map(fn ($row) => (array)$row)->all();
            }
            foreach ($this->rows as $table => $rows) foreach ($rows as $key => $row) $this->resolve($table, (string)$key);
            foreach ($before as $table => $rows) foreach ($rows as $record) {
                $query = DB::table($table);
                if (isset($record['id'])) $query->where('id', $record['id']);
                else foreach ($record as $column => $value) $query->where($column, $value);
                if ((array)$query->first() !== $record) throw new RuntimeException('Existing record was changed: '.$table);
            }
            $this->report['existing_records_verified'] = array_sum(array_map('count', $before));
            if ($commit) DB::commit(); else DB::rollBack();
            return $this->report;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function resolve(string $table, string $key): ?int
    {
        if (isset($this->ids[$table][$key])) return $this->ids[$table][$key];
        $row = $this->rows[$table][$key] ?? null;
        if (!$row) {
            $this->report['issues'][] = ['table' => $table, 'key' => $key, 'note' => 'Referenced source record is missing; link left unset.'];
            return null;
        }
        $visitKey = $table.':'.$key;
        if (isset($this->visiting[$visitKey])) throw new RuntimeException('Circular source relationship: '.$visitKey);
        $this->visiting[$visitKey] = true;
        $data = $row['data'];
        foreach ($row['refs'] ?? [] as $column => [$target, $sourceKey]) {
            $value = $this->resolve($target, (string)$sourceKey);
            if ($value !== null) $data[$column] = $value;
        }
        if ($table === 'departments') {
            $data['level'] = isset($data['parent_department_id']) ? (int)DB::table('departments')->where('id', $data['parent_department_id'])->value('level') + 1 : 1;
        }
        if ($table === 'compensation_plan_details') {
            $data['name'] = DB::table('allowances')->where('id', $data['allowance_id'])->value('name');
        }
        $existing = null;
        if (isset($this->overrides[$table][$key])) {
            $existing = DB::table($table)->find($this->overrides[$table][$key]);
            if (!$existing) throw new RuntimeException('Mapped record no longer exists: '.$visitKey);
        } elseif ($table === 'compensation_plan_details') {
            $existing = DB::table($table)->where('compensation_plan_id', $data['compensation_plan_id'])->where('allowance_id', $data['allowance_id'])->first();
        } elseif (isset($data['code'])) {
            $existing = DB::table($table)->where('code', $data['code'])->first();
            if ($existing && !$this->sameIdentity($existing, $data)) {
                $data['code'] = 'XLS-'.strtoupper(substr($table, 0, 3)).'-'.$key;
                $this->report['issues'][] = ['table' => $table, 'key' => $key, 'note' => 'Source code already belongs to a different record; new code '.$data['code'].' used.'];
                $existing = DB::table($table)->where('code', $data['code'])->first();
                if ($existing && !$this->sameIdentity($existing, $data)) throw new RuntimeException('Generated code collision: '.$visitKey);
            }
            if (!$existing && $table !== 'departments') {
                $matches = DB::table($table)->where('name', $data['name'])->get()->filter(fn ($record) => $this->sameIdentity($record, $data) && ($record->code === null || (string)$record->code === (string)$data['code']));
                if ($matches->count() === 1) $existing = $matches->first();
            }
        } else {
            $matches = DB::table($table)->where('name', $data['name'])->get()->filter(fn ($record) => $this->sameIdentity($record, $data));
            if ($matches->count() > 1) throw new RuntimeException('Ambiguous name: '.$visitKey);
            $existing = $matches->first();
        }
        if ($existing) {
            $id = $existing->id;
            foreach ($data as $column => $value) {
                if ((string)($existing->$column ?? '') !== (string)$value) $this->report['differences'][] = ['table' => $table, 'key' => $key, 'id' => $id, 'field' => $column, 'existing' => $existing->$column ?? null, 'workbook' => $value, 'action' => 'Existing value preserved'];
            }
            $action = 'matched';
        } else {
            // Some workbook allowances share the same name but have distinct codes.
            if (($this->uniqueName($table)) && DB::table($table)->where('name', $data['name'])->exists()) {
                $original = $data['name'];
                $data['name'] .= ' ('.$key.')';
                $candidate = DB::table($table)->where('name', $data['name'])->first();
                if ($candidate) throw new RuntimeException('Ambiguous duplicate name: '.$visitKey);
                $this->report['issues'][] = ['table' => $table, 'key' => $key, 'note' => 'Repeated name '.$original.' distinguished as '.$data['name']];
            }
            foreach ($data as $column => $value) if (!Schema::hasColumn($table, $column)) throw new RuntimeException('Unsupported column: '.$table.'.'.$column);
            $id = DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
            $action = 'inserted';
        }
        $this->report['summary'][$table][$action] = ($this->report['summary'][$table][$action] ?? 0) + 1;
        $this->report['mapping'][] = ['table' => $table, 'key' => $key, 'sheet' => $row['sheet'], 'row' => $row['row'], 'id' => $id, 'name' => $existing->name ?? $data['name'], 'action' => $action];
        unset($this->visiting[$visitKey]);
        return $this->ids[$table][$key] = $id;
    }

    private function sameIdentity(object $existing, array $data): bool
    {
        $names = [mb_strtolower(trim($data['name']))];
        if (isset($data['code'])) $names[] = mb_strtolower(trim($data['name']).' ('.$data['code'].')');
        if (!in_array(mb_strtolower(trim($existing->name)), $names, true)) return false;
        foreach (['company_id','parent_department_id','level','bank_id','qualification_id','certification_type_id','employment_status_id','effective_from'] as $field) {
            if (array_key_exists($field, $data) && (string)($existing->$field ?? '') !== (string)$data[$field]) return false;
        }
        return true;
    }

    private function uniqueName(string $table): bool
    {
        return (bool)config('hris_setup.'.$table.'.fields.name.unique', false);
    }
}
