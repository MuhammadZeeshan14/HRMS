<li class="flow-node">
    <article class="flow-box flow-person">
        <span class="department-meta">Employee</span>
        <a href="{{ route('employees.show', $employee->id) }}">{{ $employee->display_name }}</a>
        <span class="department-meta">{{ $employee->employee_no }}@if($employee->grade_name) · Grade {{ $employee->grade_name }}@endif · {{ ucfirst($employee->employment_status) }}</span>
        @if($employee->designation_name)<span class="department-meta">{{ $employee->designation_name }}</span>@endif
        @if($employee->department_name)<span class="department-meta">{{ $employee->department_name }}</span>@endif
        @if($employee->primary_manager)<span class="department-meta">Manager: {{ $employee->primary_manager }}</span>@endif
        @if($employee->secondary_manager)<span class="department-meta">Secondary manager: {{ $employee->secondary_manager }}</span>@endif
    </article>
</li>
