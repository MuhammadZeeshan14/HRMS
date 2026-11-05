<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AhamsonSetupSeeder extends Seeder
{
    public array $summary = [];
    public array $warnings = [];

    private const RELATIONS = [
        'bank_code' => ['banks', 'bank_id', 'code'],
        'branch_code' => ['branches', 'branch_id', 'code'],
        'company_code' => ['companies', 'company_id', 'code'],
        'parent_department_code' => ['departments', 'parent_department_id', 'code'],
        'department_code' => ['departments', 'department_id', 'code'],
        'qualification_code' => ['qualifications', 'qualification_id', 'code'],
        'certification_type_code' => ['certification_types', 'certification_type_id', 'code'],
        'grade_name' => ['grades', 'grade_id', 'name'],
    ];

    public function run(): void
    {
        $payload = json_decode(file_get_contents(database_path('seeders/data/ahamson_setup.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->summary = [];
        $this->warnings = [];
        DB::transaction(function () use ($payload) {
            DB::table('departments')->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('employees')->orderBy('id')->lockForUpdate()->get(['id']);
            $reporting = app(\App\Services\ReportingService::class);
            $beforeReporting = $reporting->invalidEdges();
            $ids = [];
            foreach ($payload['tables'] as $table => $rows) {
                $this->summary[$table] = ['source_records' => count($rows), 'inserted' => 0, 'matched' => 0];
                foreach ($rows as $index => $row) {
                    $data = $row['data'];
                    $code = $data['code'] ?? null;
                    $query = DB::table($table);
                    if ($code !== null) {
                        $query->where('code', $code);
                    } else {
                        $query->where('name', $data['name']);
                    }
                    $existing = $query->first();
                    if (! $existing && $code !== null && ! in_array($table, ['departments', 'branches', 'specializations'])) {
                        // Enrich a matching existing master with its workbook code.
                        $matches = DB::table($table)->where('name', $data['name'])->whereNull('code')->get();
                        if ($matches->count() === 1) {
                            $existing = $matches->first();
                        }
                    }
                    if ($existing && $code !== null && $existing->name !== $data['name']) {
                        throw new RuntimeException("Conflicting code {$code} in {$table}: {$existing->name} / {$data['name']}");
                    }
                    $values = array_filter($data, fn ($value) => $value !== null);
                    if ($table === 'designations') {
                        $values = array_intersect_key($values, array_flip(['code', 'name', 'description', 'status']));
                    }
                    foreach (self::RELATIONS as $field => $relation) {
                        unset($values[$field]);
                    }
                    foreach (array_keys($values) as $column) {
                        if (! Schema::hasColumn($table, $column)) {
                            throw new RuntimeException("Unmapped column {$table}.{$column}");
                        }
                    }
                    if ($existing) {
                        $id = $existing->id;
                        $this->updateIfChanged($table, $id, $values);
                        $this->summary[$table]['matched']++;
                    } else {
                        // Required foreign keys are resolved immediately for these tables.
                        foreach (['branches' => 'bank_code', 'specializations' => 'qualification_code'] as $requiredTable => $field) {
                            if ($table === $requiredTable) {
                                [$target, $column, $key] = self::RELATIONS[$field];
                                $foreignId = DB::table($target)->where($key, $data[$field])->value('id');
                                if (! $foreignId) {
                                    throw new RuntimeException("Missing {$field} at {$row['sheet']} row {$row['row']}");
                                }
                                $values[$column] = $foreignId;
                            }
                        }
                        $id = DB::table($table)->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
                        $this->summary[$table]['inserted']++;
                    }
                    $ids[$table][$index] = $id;
                }
            }
            foreach ($payload['tables'] as $table => $rows) {
                if ($table === 'designations') {
                    continue;
                }
                foreach ($rows as $index => $row) {
                    $values = [];
                    foreach (self::RELATIONS as $field => [$target, $column, $key]) {
                        $reference = $row['data'][$field] ?? null;
                        if ($reference === null) {
                            continue;
                        }
                        $foreignId = DB::table($target)->where($key, $reference)->value('id');
                        if (! $foreignId) {
                            throw new RuntimeException("Missing {$field}={$reference} at {$row['sheet']} row {$row['row']}");
                        }
                        $values[$column] = $foreignId;
                        if (! $foreignId) {
                            $this->warnings[] = "{$row['sheet']} row {$row['row']}: reporting designation code {$reference} is absent; source code retained, link unset.";
                        }
                    }
                    $this->updateIfChanged($table, $ids[$table][$index], $values);
                }
            }
            app(\App\Services\DepartmentLevelService::class)->synchronize();
            $reporting->protect($beforeReporting);
        });
        $report = ['source' => $payload['source'], 'source_sha256' => $payload['sha256'], 'tables' => $this->summary, 'warnings' => $this->warnings,
            'excluded_sheets' => $payload['excluded_sheets'], 'generated_designation_codes' => $payload['generated_designation_codes']];
        if ($this->command) {
            $this->command->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    private function updateIfChanged(string $table, int $id, array $values): void
    {
        if (! $values) {
            return;
        }
        $record = DB::table($table)->find($id);
        $changed = array_filter($values, fn ($value, $column) => ($value === null) !== ($record->$column === null) || (string) $value !== (string) $record->$column, ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            DB::table($table)->where('id', $id)->update($changed + ['updated_at' => now()]);
        }
    }
}
