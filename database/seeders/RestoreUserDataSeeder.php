<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RestoreUserDataSeeder extends Seeder
{
    public function run(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'ahamson_hris') {
            throw new RuntimeException('Restore is restricted to ahamson_hris MySQL.');
        }
        $source = new \PDO('sqlite:'.database_path('hris-rebuilt.sqlite'), options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $mapped = $visiting = $inserted = [];
        $restore = function (string $table, int $sourceId) use (&$restore, &$mapped, &$visiting, &$inserted, $source): int {
            $key = $table.':'.$sourceId;
            if (isset($mapped[$key])) {
                return $mapped[$key];
            }
            if (isset($visiting[$key])) {
                throw new RuntimeException('Circular source relationship: '.$key);
            }
            $visiting[$key] = true;
            $statement = $source->prepare('SELECT * FROM "'.$table.'" WHERE id=?');
            $statement->execute([$sourceId]);
            $record = $statement->fetch(\PDO::FETCH_ASSOC);
            if (! $record) {
                throw new RuntimeException('Missing source record: '.$key);
            }
            unset($record['id']);
            foreach ($source->query('PRAGMA foreign_key_list("'.$table.'")')->fetchAll(\PDO::FETCH_ASSOC) as $relation) {
                $field = $relation['from'];
                if ($record[$field] !== null) {
                    $record[$field] = $restore($relation['table'], (int) $record[$field]);
                }
            }
            $query = DB::table($table);
            if ($table === 'users') {
                $query->where('email', $record['email']);
            } elseif ($table === 'employees') {
                $query->where('employee_no', $record['employee_no']);
            } elseif (! empty($record['code'])) {
                $query->where('code', $record['code']);
                if ($table === 'branches') {
                    $query->where('bank_id', $record['bank_id']);
                }
            } elseif (isset($record['employee_id'])) {
                foreach ($record as $field => $value) {
                    if (! in_array($field, ['created_at', 'updated_at'])) {
                        $query->where($field, $value);
                    }
                }
            } elseif (isset($record['name'])) {
                $query->where('name', $record['name']);
            } else {
                throw new RuntimeException('No safe identity for '.$table);
            }
            $existing = $query->first();
            if ($existing) {
                $id = $existing->id;
            } else {
                $id = DB::table($table)->insertGetId($record);
                $inserted[$table] = ($inserted[$table] ?? 0) + 1;
            }
            unset($visiting[$key]);

            return $mapped[$key] = $id;
        };
        DB::transaction(function () use ($source, $restore) {
            foreach (['users', 'employees', 'employee_languages', 'employee_trainings', 'employee_certifications', 'employee_qualifications', 'employee_experiences', 'employee_relatives', 'employee_references', 'employee_projects', 'salary_histories', 'employee_documents', 'employee_exits'] as $table) {
                foreach ($source->query('SELECT id FROM "'.$table.'" ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                    $restore($table, (int) $id);
                }
            }
        });
        $this->command?->line(json_encode(['inserted' => $inserted, 'users' => DB::table('users')->count(), 'employees' => DB::table('employees')->count()], JSON_PRETTY_PRINT));
    }
}
