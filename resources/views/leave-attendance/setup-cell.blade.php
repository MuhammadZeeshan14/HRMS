@if($kind === 'holidays' && $field === 'date')
    {{ sprintf('%02d/%02d', $record->day, $record->month) }}
@elseif($field === 'employee')
    {{ $record->employee->employee_no }} · {{ $record->employee->display_name }}
@elseif($field === 'employeeStatus')
    {{ $record->employeeStatus?->name ?? 'Employee status not configured' }}
@elseif($field === 'shift')
    #{{ $record->shift_id }} · {{ $record->shift->name }}
@elseif($field === 'leaveType')
    {{ $record->leaveType->name }}
@elseif($field === 'overnight')
    {{ $record->overnight ? 'Next calendar day (overnight)' : 'Same calendar day' }}
@elseif(in_array($field, ['start_time','end_time']))
    {{ \App\Support\HrlaTime::clock($record->$field) }}
@elseif(in_array($field, ['description','notes']) && filled($record->$field))
    <details class="master-cell-details"><summary>{{ str($record->$field)->limit(65) }}</summary><div>{{ $record->$field }}</div></details>
@else
    @if($record->$field !== null && $record->$field !== ''){{ $record->$field }}@else<span class="master-empty-value">—</span>@endif
@endif
