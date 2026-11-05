@php($assignmentOn = request('assignment_on') ?: now()->toDateString())
@php($oldSelection = old('employee_ids_payload'))
<section class="card master-table" data-shift-table data-storage-key="hrla-shifts-selection-{{ auth()->id() }}-{{ $assignmentOn }}"
         data-saved="{{ session('shift_assignment_saved') || session('shift_assignment_edit_saved') ? '1' : '0' }}"
         data-initial="{{ json_encode(old('employee_ids', is_string($oldSelection) ? json_decode($oldSelection, true) : [])) }}"
         data-initial-assignments="{{ json_encode(old('assignment_ids', [])) }}"
         data-reopen="{{ $errors->any() && old('_bulk_action') === 'set' ? 'set' : '' }}">
    <div class="card-head"><h2>Employee shift assignments</h2><span>{{ $assignmentEmployees->total() }} {{ str('employee')->plural($assignmentEmployees->total()) }} found</span></div>
    <form method="get" class="form-grid master-form" action="{{ route('la.setup', 'assignments') }}">
        <label>Search employees<input name="employee_q" value="{{ request('employee_q') }}" placeholder="Employee number or name"></label>
        <label>Company<select name="company_id"><option value="">All companies</option>@foreach($options['companies'] as $company)<option value="{{ $company->id }}" @selected((string)request('company_id') === (string)$company->id)>{{ $company->name }}</option>@endforeach</select></label>
        <label>Department<select name="department_id"><option value="">All departments</option>@foreach($options['departments'] as $department)<option value="{{ $department->id }}" @selected((string)request('department_id') === (string)$department->id)>{{ $department->name }}</option>@endforeach</select></label>
        <label>Employment status<select name="status"><option value="">All statuses</option>@foreach(['active','inactive','on_leave','exited'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
        <label>Shift<select name="current_shift"><option value="">All shifts</option><option value="unassigned" @selected(request('current_shift') === 'unassigned')>Unassigned</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}" @selected((string)request('current_shift') === (string)$shift->id)>{{ $shift->name }}</option>@endforeach</select></label>
        <label>Assignment on<input type="date" name="assignment_on" value="{{ $assignmentOn }}" required></label>
        <div class="form-actions"><button class="btn">Search / Filter</button><a class="btn" href="{{ route('la.setup', 'assignments') }}">Clear filters</a></div>
    </form>
    <div class="form-actions">
        <strong data-selection-count aria-live="polite">0 employees selected</strong>
        <button type="button" class="btn" data-clear-selection>Clear selection</button>
        <button type="button" class="btn primary" data-open-shift-dialog="set" disabled>Set Shift</button>
    </div>
    <p class="la-note" data-selection-note aria-live="polite">Select employees, then choose Set Shift. The latest saved assignment takes priority for its effective dates.</p>

    <div class="master-list-scroll" role="region" aria-label="Employees for shift assignment" tabindex="0">
        <table class="master-records shift-assignment-employees">
            <caption class="sr-only">Select employees, then set their shift using one modal</caption>
            <thead><tr><th scope="col"><input type="checkbox" data-select-page aria-label="Select all employees on this page"></th><th scope="col">Employee number</th><th scope="col">Employee name</th><th scope="col">Company</th><th scope="col">Department</th><th scope="col">Assigned shift</th><th scope="col">Effective from</th><th scope="col">Effective to</th></tr></thead>
            <tbody>
            @forelse($assignmentEmployees as $employee)
                @php($assignment = $currentAssignments->get($employee->id))
                <tr><td><input type="checkbox" data-select-employee value="{{ $employee->id }}" data-assignment-id="{{ $assignment?->id }}" aria-label="Select {{ $employee->employee_no }} {{ $employee->display_name }}"></td>
                    <td>{{ $employee->employee_no }}</td><td>{{ $employee->display_name }}</td><td>{{ $employee->company?->name ?? '—' }}</td><td>{{ $employee->department?->name ?? '—' }}</td>
                    <td>{{ $assignment?->shift?->name ?? 'Unassigned' }}</td><td>{{ $assignment?->starts_on ?? '—' }}</td><td>{{ $assignment ? ($assignment->ends_on ?? 'Open-ended') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">No employees match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pager">{{ $assignmentEmployees->links() }}</div>
    @foreach(['set' => 'Set Shift'] as $action => $title)
        <dialog class="master-edit-dialog" data-shift-dialog="{{ $action }}" aria-labelledby="shift-{{ $action }}-title">
            <div class="card-head"><h2 id="shift-{{ $action }}-title">{{ $title }}</h2><button type="button" class="btn" data-close-edit aria-label="Close {{ $title }}">Close</button></div>
            <form method="post" action="{{ route('la.setup.assignments.bulk') }}" class="form-grid master-form" data-shift-form="{{ $action }}">
                @csrf
                <input type="hidden" name="_bulk_action" value="{{ $action }}">
                <input type="hidden" name="employee_ids_payload" value="[]">
                <p class="wide" data-modal-count></p>
                @if($errors->any() && old('_bulk_action') === $action)<div class="alert danger wide" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <label>Shift<select name="shift_id" required><option value="">Select shift</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}" @selected(old('_bulk_action') === $action && (string)old('shift_id') === (string)$shift->id)>{{ $shift->name }} · {{ \App\Support\HrlaTime::clock($shift->start_time) }} – {{ \App\Support\HrlaTime::clock($shift->end_time) }}{{ $shift->overnight ? ' (next day)' : '' }}</option>@endforeach</select></label>
                <label>Effective from<input type="date" name="starts_on" value="{{ old('_bulk_action') === $action ? old('starts_on') : $assignmentOn }}" required></label>
                <label>Effective to<input type="date" name="ends_on" value="{{ old('_bulk_action') === $action ? old('ends_on') : '' }}"><small>Leave blank for an open-ended assignment.</small></label>
                <p class="wide">The selected employees receive the same shift. This new assignment overrides older assignments within these inclusive dates. Outside this range, earlier assignments may apply. Previous assignments and saved attendance records remain in history.</p>
                <div class="form-actions wide"><button class="btn primary">Save shift for selected employees</button><button type="button" class="btn" data-close-edit>Cancel</button></div>
            </form>
        </dialog>
    @endforeach
</section>
