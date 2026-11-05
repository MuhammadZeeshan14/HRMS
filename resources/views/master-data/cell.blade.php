@php
    $type = $meta['type'] ?? 'text';
    $cellValue = $record->$field ?? null;
@endphp
@if($type === 'multiple')
    {{ collect(($field === 'department_ids' ? $unitDepartments : $newsCompanies)[$record->id] ?? [])->map(fn ($id) => ($options[$field][$id] ?? 'Unavailable').($field === 'department_ids' ? ' (Level '.$departmentLevels[$id].')' : ''))->implode(', ') ?: '—' }}
@elseif($cellValue === null || $cellValue === '')
    <span class="master-empty-value">—</span>
@elseif($type === 'relation')
    {{ $options[$field][$cellValue] ?? 'Unavailable' }}
@elseif($type === 'select')
    <span @class(['master-status' => $field === 'status', 'master-status-active' => $field === 'status' && $cellValue === 'active'])>{{ $meta['options'][$cellValue] ?? $cellValue }}</span>
@elseif($type === 'boolean')
    {{ $cellValue ? 'Yes' : 'No' }}
@elseif($type === 'file')
    <a href="{{ route('master-data.download', [$master, $record->id, $field]) }}">Download</a>
@elseif($type === 'decimal')
    {{ number_format((float)$cellValue, 2) }}
@elseif($type === 'textarea' && mb_strlen($cellValue) > 120)
    <details class="master-cell-details"><summary>{{ str($cellValue)->limit(100) }}</summary><div>{{ $cellValue }}</div></details>
@else
    {{ $cellValue }}
@endif
