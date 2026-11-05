@foreach($config['fields'] as $field => $meta)
    @php
        $type = $meta['type'] ?? 'text';
        $useOld = session()->hasOldInput() && (string)old('_edit_record_id', '') === (string)($record->id ?? '');
        $value = $record ? ($type === 'multiple' ? (($field === 'department_ids' ? $unitDepartments : $newsCompanies)[$record->id] ?? []) : $record->$field) : ($meta['default'] ?? ($type === 'boolean' ? 0 : ''));
        if ($useOld) { $value = old($field, $type === 'multiple' ? [] : $value); }
        $required = !($meta['optional'] ?? false) && !($type === 'file' && $record && $record->$field);
        $size = match (true) {
            in_array($type, ['textarea', 'multiple']) => 'full',
            in_array($field, ['name', 'title', 'address']) => 'long',
            $type === 'relation', $type === 'file' => 'medium',
            in_array($type, ['integer', 'decimal', 'boolean', 'date', 'select']), $field === 'code' => 'short',
            default => 'medium',
        };
    @endphp
    @if($field === 'department_ids')<div class="wide" role="group" aria-label="Departments">Departments
    @else<label class="field-{{ $size }} {{ in_array($type, ['textarea','multiple']) ? 'wide' : '' }}">{{ $meta['label'] }}{{ $required && $type !== 'boolean' ? ' *' : '' }}@endif
        @if($field === 'department_ids')
            <span class="department-options">
                @forelse($options[$field] as $key => $text)
                    <label class="department-option"><input type="checkbox" name="department_ids[]" value="{{ $key }}" @checked(in_array($key, (array)$value))><span>{{ $text }}</span></label>
                @empty
                    <span>No departments available. Create a department first.</span>
                @endforelse
            </span>
        @elseif(in_array($type, ['relation','multiple','select']))
            <select name="{{ $field }}{{ $type === 'multiple' ? '[]' : '' }}" @if($type === 'multiple') multiple size="4" @endif @required($required)>
                @if($type !== 'multiple')<option value="">Please select</option>@endif
                @foreach($type === 'select' ? $meta['options'] : $options[$field] as $key => $text)
                    @if(!($record && in_array($field, ['parent_department_id']) && $key == $record->id))
                        <option value="{{ $key }}" @if($master === 'departments' && $field === 'parent_department_id') data-level="{{ $departmentLevels[$key] }}" @endif @selected($type === 'multiple' ? in_array($key, (array)$value) : (string)$value === (string)$key)>{{ $text }}</option>
                    @endif
                @endforeach
            </select>
            @if($type === 'multiple')<small>Select multiple options with Ctrl (Windows) or Command (Mac). Leave all unselected to clear optional selections.</small>@endif
        @elseif($master === 'departments' && $field === 'level')
            @php($parentId = $useOld ? old('parent_department_id') : ($record->parent_department_id ?? null))
            <input type="number" name="level" value="{{ $parentId ? ($departmentLevels[$parentId] ?? 0) + 1 : 1 }}" readonly aria-label="Level (automatic)" data-field-size="8" data-department-level>
            <small>Calculated from the parent. Top level is 1.</small>
        @elseif($type === 'boolean')
            <input type="hidden" name="{{ $field }}" value="0"><input type="checkbox" name="{{ $field }}" value="1" @checked($value)>
        @elseif($type === 'textarea')
            <textarea name="{{ $field }}" rows="4" maxlength="10000" @required($required)>{{ $value }}</textarea>
        @elseif($type === 'file')
            <input type="file" name="{{ $field }}" @required($required)>
            @if($record && $record->$field)<a href="{{ route('master-data.download', [$master, $record->id, $field]) }}">Download current file</a>@endif
        @else
            <input type="{{ $type === 'date' ? 'date' : (in_array($type, ['decimal','integer']) ? 'number' : 'text') }}" name="{{ $field }}" value="{{ $value }}" @if($meta['size'] ?? null) data-field-size="{{ $meta['size'] }}" @endif @required($required) @if(in_array($type, ['decimal','integer'])) min="{{ $field === 'rank' ? 1 : 0 }}" step="{{ $type === 'decimal' ? '0.01' : '1' }}" @else maxlength="{{ $meta['max'] ?? 255 }}" @endif>
        @endif
    @if($field === 'department_ids')</div>@else</label>@endif
@endforeach
