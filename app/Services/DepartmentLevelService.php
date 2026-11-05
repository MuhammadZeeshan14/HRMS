<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepartmentLevelService
{
    public function paths(Collection $rows): array
    {
        $departments = $rows->keyBy('id');
        $paths = [];
        foreach ($departments as $id => $department) {
            $names = [];
            $visited = [];
            $cursor = $id;
            while ($cursor !== null && isset($departments[$cursor]) && ! isset($visited[$cursor])) {
                $visited[$cursor] = true;
                $names[] = $departments[$cursor]->name;
                $cursor = $departments[$cursor]->parent_department_id;
            }
            $paths[$id] = implode(' → ', array_reverse($names));
        }
        return $paths;
    }

    public function levels(Collection $rows): array
    {
        $parents = $rows->pluck('parent_department_id', 'id')->all();
        $levels = [];
        foreach (array_keys($parents) as $id) {
            $path = [];
            $cursor = $id;
            while ($cursor !== null && !isset($levels[$cursor])) {
                if (isset($path[$cursor])) {
                    throw ValidationException::withMessages(['parent_department_id' => 'The parent department cannot contain a cycle.']);
                }
                if (!array_key_exists($cursor, $parents)) {
                    throw ValidationException::withMessages(['parent_department_id' => 'The selected parent department is missing.']);
                }
                $path[$cursor] = true;
                $cursor = $parents[$cursor];
            }
            $level = $cursor === null ? 0 : $levels[$cursor];
            foreach (array_reverse(array_keys($path)) as $node) {
                $levels[$node] = ++$level;
            }
        }
        return $levels;
    }

    public function synchronize(): void
    {
        DB::transaction(function () {
            $rows = DB::table('departments')->orderBy('id')->lockForUpdate()->get(['id', 'parent_department_id', 'level']);
            $levels = $this->levels($rows);
            foreach ($rows as $row) {
                if ($row->level === null || (int) $row->level !== $levels[$row->id]) {
                    DB::table('departments')->where('id', $row->id)->update(['level' => $levels[$row->id], 'updated_at' => now()]);
                }
            }
        });
    }
}
