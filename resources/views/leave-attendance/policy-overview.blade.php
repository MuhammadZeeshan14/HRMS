<section class="card master-table">
    <div class="card-head"><h2>Leave policies by employee status</h2></div>
    <form method="get" action="{{ route('la.setup', 'policies') }}" class="form-grid master-form" data-policy-filter>
        <label class="field-medium">Employee status<select name="employment_status_id" data-policy-status required><option value="">Select HRIS employee status</option>@foreach($employeeStatuses as $status)<option value="{{ $status->id }}" @selected($selectedStatus?->id === $status->id)>{{ $status->name }}{{ $status->status === 'inactive' ? ' (inactive)' : '' }}</option>@endforeach</select></label>
        @if($selectedStatus)
            <label class="field-long">Search employee<input type="search" data-policy-employee-search list="policy-employee-results" autocomplete="off" placeholder="{{ $policyEmployees->isEmpty() ? 'No employees with this status' : 'Search employee number or name' }}" value="{{ $policyEmployee ? $policyEmployee->employee_no.' · '.$policyEmployee->display_name : '' }}" @disabled($policyEmployees->isEmpty()) @required($policyEmployees->isNotEmpty())></label>
            <input type="hidden" name="employee_id" data-policy-employee value="{{ $policyEmployee?->id }}">
            <datalist id="policy-employee-results">@foreach($policyEmployees as $employee)<option value="{{ $employee->employee_no }} · {{ $employee->display_name }}" data-employee-id="{{ $employee->id }}"></option>@endforeach</datalist>
        @endif
        <label class="field-short">Balance year<input type="number" name="balance_year" min="1900" max="9999" value="{{ $balanceYear }}" required></label>
        <div class="form-actions"><a class="btn" href="{{ route('master-data.index', 'employment_statuses') }}">Manage statuses</a></div>
    </form>
    @if($selectedStatus)
        <div class="card-head"><h2>{{ $selectedStatus->name }} · All leave types</h2><span>{{ $policyRows->count() }} {{ str('leave type')->plural($policyRows->count()) }}</span></div>
        <p>Allowances reset each January. @if($policyEmployee)Used and left are for <strong>{{ $policyEmployee->employee_no }} · {{ $policyEmployee->display_name }}</strong> in <strong>{{ $balanceYear }}</strong> (January–December).@else No employees currently have this status; allowances can still be configured.@endif</p>
        <p class="la-note">Leave used counts approved requests only. Pending requests do not reduce the balance.</p>
        <div class="master-list-scroll" role="region" aria-label="Leave policy allowances and usage" tabindex="0">
            <table class="master-records policy-allowance-table">
                <caption class="sr-only">All saved leave types, assigned allowances and selected employee balances</caption>
                <thead><tr><th scope="col">Leave type</th><th scope="col">Total leaves assigned</th><th scope="col">Leave used</th><th scope="col">Leaves left</th><th scope="col" class="master-actions-column">Actions</th></tr></thead>
                <tbody>
                @forelse($policyRows as $row)
                    @php($policy = $row['policy'])
                    <tr data-policy-type="{{ $row['type']->id }}">
                        <td>{{ $row['type']->code }} · {{ $row['type']->name }}<br><small>@if(!$policy)Not configured @elseif($policy->allowance_kind !== 'numeric')Approval rules pending @elseif($policy->allowance === null)Setup incomplete @else Annual allowance @endif</small></td>
                        <td>@if($policy?->allowance_kind === 'numeric' && $policy->allowance !== null){{ number_format((float)$policy->allowance, 2) }}@elseif($policy){{ $policy->allowance_kind === 'as_needed' ? 'As needed' : 'Unspecified' }}@else Not configured @endif</td>
                        <td>{{ $row['used'] !== null ? number_format($row['used'], 2) : '—' }}</td>
                        <td>{{ $row['remaining'] !== null ? number_format($row['remaining'], 2) : '—' }}</td>
                        <td class="master-actions-column"><div class="master-row-actions"><button type="button" class="btn" data-edit-record="policy-type-{{ $row['type']->id }}">{{ $policy ? 'Edit' : 'Configure' }}</button>@if($policy)<form method="post" action="{{ route('la.setup.delete', ['policies', $policy->id]) }}" onsubmit="return confirm('Delete this policy? Referenced history is protected.')">@csrf @method('DELETE')<button class="btn danger">Delete</button></form>@endif</div></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No leave types saved. Add leave types in HRLA Setup first.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @foreach($policyRows as $row)
            @php($record = $row['policy'])
            @php($policyType = $row['type'])
            <dialog id="policy-type-{{ $policyType->id }}" class="master-edit-dialog" aria-labelledby="policy-title-{{ $policyType->id }}"
                    @if(($errors->any() && (string)old('leave_type_id') === (string)$policyType->id && (string)old('employment_status_id') === (string)$selectedStatus->id) || ($edit && $record?->id === $edit->id && !$errors->any())) data-reopen-edit @endif>
                <div class="card-head"><h2 id="policy-title-{{ $policyType->id }}">{{ $selectedStatus->name }} · {{ $policyType->name }}</h2><button type="button" class="btn" data-close-edit aria-label="Close policy form">Close</button></div>
                @if($errors->any() && (string)old('leave_type_id') === (string)$policyType->id)<div class="alert danger" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <form method="post" action="{{ $record ? route('la.setup.update', ['policies', $record->id]) : route('la.setup.store', 'policies') }}" class="form-grid master-form">
                    @csrf @if($record) @method('PUT') @endif
                    <input type="hidden" name="_edit_record_id" value="{{ $record?->id }}"><input type="hidden" name="_balance_employee_id" value="{{ $policyEmployee?->id }}"><input type="hidden" name="_balance_year" value="{{ $balanceYear }}">
                    @include('leave-attendance.setup-fields', ['fixedPolicyContext' => true])
                    <div class="form-actions wide"><button class="btn primary">Save policy</button><button type="button" class="btn" data-close-edit>Cancel</button></div>
                </form>
            </dialog>
        @endforeach
    @else
        <p class="empty">Select an Employee status to view all saved leave types and their assigned allowances.</p>
    @endif
</section>
<script src="{{ asset('leave-policy-overview.js') }}" defer></script>
