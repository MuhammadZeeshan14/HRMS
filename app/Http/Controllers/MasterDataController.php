<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetupRecordRequest;
use App\Services\DepartmentLevelService;
use App\Services\MasterScope;
use App\Services\ReportingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MasterDataController extends Controller
{
    public function index(Request $request, ?string $master = null)
    {
        $master ??= array_key_first(config('hris_setup'));
        $config = $this->config($master);
        $masters = config('hris_setup');
        $counts = collect($masters)->mapWithKeys(fn ($value, $table) => [$table => $this->scoped($table)->count()]);
        $query = $this->scoped($master);
        if ($request->filled('q')) {
            $query->where($master === 'news' ? 'title' : 'name', 'like', '%'.$request->string('q').'%');
        }
        $records = $query->orderBy($master === 'grades' ? 'rank' : ($master === 'news' ? 'published_on' : 'name'))->paginate(25)->withQueryString();
        $options = [];
        foreach ($config['fields'] as $field => $meta) {
            if (isset($meta['table'])) {
                $options[$field] = $this->scoped($meta['table'])->orderBy('name')->pluck('name', 'id');
            }
        }
        $newsCompanies = $master === 'news' ? DB::table('news_companies')->whereIn('news_id', $records->pluck('id'))->get()->groupBy('news_id')->map(fn ($rows) => $rows->pluck('company_id')->all()) : collect();
        $departmentLevels = $master === 'departments' || collect($config['fields'])->contains(fn ($meta) => ($meta['table'] ?? null) === 'departments')
            ? app(DepartmentLevelService::class)->levels(DB::table('departments')->get(['id', 'parent_department_id']))
            : [];
        if ($departmentLevels) {
            $paths = app(DepartmentLevelService::class)->paths(DB::table('departments')->get(['id', 'name', 'parent_department_id']));
            foreach ($config['fields'] as $field => $meta) {
                if (($meta['table'] ?? null) === 'departments') {
                    $options[$field] = $options[$field]->map(fn ($name, $id) => $paths[$id] ?? $name);
                }
            }
        }

        return view('master-data.index', compact('master', 'config', 'masters', 'counts', 'records', 'options', 'newsCompanies', 'departmentLevels'));
    }

    public function store(SetupRecordRequest $request, string $master)
    {
        return $this->save($request, $master);
    }

    public function update(SetupRecordRequest $request, string $master, int $id)
    {
        return $this->save($request, $master, $id);
    }

    private function save(SetupRecordRequest $request, string $master, ?int $id = null)
    {
        $config = $this->config($master);
        $record = $id ? $this->scoped($master)->find($id) : null;
        abort_if($id && ! $record, 404);
        $data = $request->validated();
        if (Schema::hasColumn($master, 'company_id') && ! $id && empty($data['company_id'])) {
            $data['company_id'] = $request->user()->employee->company_id;
        }
        if ($master === 'hr_documents') {
            $data['company_id'] = $request->user()->employee->company_id;
        }
        app(MasterScope::class)->write($master, $data, $id);
        if ($master === 'compensation_plan_details') {
            $data['name'] = DB::table('allowances')->where('id', $data['allowance_id'])->value('name');
        }
        $companies = $data['company_ids'] ?? [];
        unset($data['company_ids']);
        if ($master === 'news' && ! $id) {
            $data['status'] = 'published';
        }
        $newFiles = [];
        $oldFiles = [];
        try {
            foreach ($config['fields'] as $field => $meta) {
                if (($meta['type'] ?? '') !== 'file') {
                    continue;
                }
                unset($data[$field]);
                if ($request->hasFile($field)) {
                    if ($master === 'hr_documents') {
                        $extensions = DB::table('document_types')->where('id', $data['document_type_id'])->value('file_extensions');
                        $allowed = array_filter(preg_split('/[\s,;|]+/', strtolower(str_replace('.', '', $extensions ?? ''))));
                        if ($allowed && ! in_array(strtolower($request->file($field)->extension()), $allowed, true)) {
                            throw ValidationException::withMessages([$field => 'This file extension is not allowed by the selected document type.']);
                        }
                    }
                    $path = $request->file($field)->store('hris-setup', 'local');
                    $newFiles[] = $path;
                    $data[$field] = $path;
                    if ($record && $record->$field) {
                        $oldFiles[] = $record->$field;
                    }
                }
            }
            DB::transaction(function () use ($master, $data, $companies, &$id) {
                if ($master === 'departments') {
                    DB::table('departments')->orderBy('id')->lockForUpdate()->get(['id']);
                    DB::table('employees')->orderBy('id')->lockForUpdate()->get(['id']);
                }
                $before = app(ReportingService::class)->invalidEdges();
                if ($id) {
                    $this->scoped($master)->where('id', $id)->update($data + ['updated_at' => now()]);
                } else {
                    $id = $this->scoped($master)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
                }
                if ($master === 'departments') {
                    app(DepartmentLevelService::class)->synchronize();
                    app(ReportingService::class)->protect($before);
                }
                if ($master === 'news') {
                    DB::table('news_companies')->where('news_id', $id)->delete();
                    foreach ($companies as $company) {
                        DB::table('news_companies')->insert(['news_id' => $id, 'company_id' => $company]);
                    }
                }
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($newFiles);
            throw $error;
        }
        Storage::disk('local')->delete($oldFiles);

        return back()->with('success', $config['label'].' record saved.');
    }

    public function download(string $master, int $id, string $field)
    {
        $config = $this->config($master);
        abort_unless(($config['fields'][$field]['type'] ?? '') === 'file', 404);
        $path = $this->scoped($master)->where('id', $id)->value($field);
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    public function destroy(string $master, int $id)
    {
        $config = $this->config($master);
        $record = $this->scoped($master)->find($id);
        abort_unless($record, 404);
        app(MasterScope::class)->write($master, (array) $record, $id);
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $foreign) {
                if ($foreign['foreign_table'] !== $master || count($foreign['columns']) !== 1 || $foreign['foreign_columns'] !== ['id']) {
                    continue;
                }
                if (DB::table($table['name'])->where($foreign['columns'][0], $id)->exists()) {
                    return back()->withErrors(['master' => 'This record is in use and cannot be deleted. Set it to inactive instead.']);
                }
            }
        }
        try {
            $this->scoped($master)->where('id', $id)->delete();
        } catch (QueryException) {
            return back()->withErrors(['master' => 'This record is in use and cannot be deleted. Set it to inactive instead.']);
        }
        foreach ($config['fields'] as $field => $meta) {
            if (($meta['type'] ?? '') === 'file' && $record->$field) {
                Storage::disk('local')->delete($record->$field);
            }
        }

        return back()->with('success', $config['label'].' record deleted.');
    }

    private function scoped(string $table)
    {
        return app(MasterScope::class)->query($table);
    }

    private function config(string $master): array
    {
        $masters = config('hris_setup');
        abort_unless(array_key_exists($master, $masters), 404);

        return $masters[$master];
    }
}
