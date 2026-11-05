<?php

// One-time transfer into an empty, migrated MySQL database. Source is read-only.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'ahamson_hris') {
    throw new RuntimeException('This transfer is restricted to the configured ahamson_hris MySQL database.');
}
$source = new PDO('sqlite:'.__DIR__.'/database.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = $source->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name != 'migrations'")->fetchAll(PDO::FETCH_COLUMN);
$connection = DB::connection('mysql');
$connection->statement('SET FOREIGN_KEY_CHECKS=0');
$summary = [];
try {
    $connection->transaction(function () use ($source, $tables, $connection, &$summary) {
        foreach ($tables as $table) {
            if ($connection->table($table)->count() !== 0) {
                throw new RuntimeException('Destination must be empty: '.$table);
            }
            $rows = $source->query('SELECT * FROM "'.str_replace('"', '""', $table).'"')->fetchAll(PDO::FETCH_ASSOC);
            foreach (array_chunk($rows, 100) as $chunk) {
                $connection->table($table)->insert($chunk);
            }
            $summary[$table] = count($rows);
            $columns = $source->query('PRAGMA table_info("'.str_replace('"', '""', $table).'")')->fetchAll(PDO::FETCH_ASSOC);
            $primary = array_column(array_filter($columns, fn ($column) => $column['pk'] > 0), 'name');
            $query = $connection->table($table);
            foreach ($primary as $column) {
                $query->orderBy($column);
            }
            $expected = $source->query('SELECT * FROM "'.str_replace('"', '""', $table).'"'.($primary ? ' ORDER BY '.implode(',', array_map(fn ($column) => '"'.$column.'"', $primary)) : ''))->fetchAll(PDO::FETCH_ASSOC);
            $actual = $query->get()->map(fn ($row) => (array) $row)->all();
            $types = $connection->select('SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            $numeric = array_column(array_filter($types, fn ($column) => in_array($column->DATA_TYPE, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'])), 'COLUMN_NAME');
            if (count($expected) !== count($actual)) {
                throw new RuntimeException('Count mismatch: '.$table);
            }
            foreach ($expected as $index => $record) {
                foreach ($record as $field => $value) {
                    $copied = $actual[$index][$field];
                    $equal = $value === null ? $copied === null : ($copied !== null && (in_array($field, $numeric) ? (float) $value === (float) $copied : (string) $value === (string) $copied));
                    if (! $equal) {
                        throw new RuntimeException('Value mismatch: '.$table.'.'.$field.' row '.$index);
                    }
                }
            }
        }
        $keys = $connection->select('SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL');
        foreach ($keys as $key) {
            $missing = $connection->table($key->TABLE_NAME.' as child')->whereNotNull('child.'.$key->COLUMN_NAME)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from($key->REFERENCED_TABLE_NAME.' as parent')->whereColumn('parent.'.$key->REFERENCED_COLUMN_NAME, 'child.'.$key->COLUMN_NAME))->count();
            if ($missing) {
                throw new RuntimeException('Orphaned foreign key: '.$key->TABLE_NAME.'.'.$key->COLUMN_NAME);
            }
        }
    });
} finally {
    $connection->statement('SET FOREIGN_KEY_CHECKS=1');
}
foreach ($source->query('SELECT name,seq FROM sqlite_sequence')->fetchAll(PDO::FETCH_ASSOC) as $sequence) {
    if (isset($summary[$sequence['name']])) {
        $table = $connection->getQueryGrammar()->wrapTable($sequence['name']);
        $connection->statement('ALTER TABLE '.$table.' AUTO_INCREMENT = '.((int) $sequence['seq'] + 1));
    }
}
echo json_encode(['database' => 'ahamson_hris', 'verified_tables' => count($summary), 'preserved_records' => $summary, 'foreign_key_violations' => 0], JSON_PRETTY_PRINT).PHP_EOL;
