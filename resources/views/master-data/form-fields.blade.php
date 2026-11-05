@if($master === 'companies')
    @php
        $bankingFields = array_intersect_key($config['fields'], array_flip(['bank_id', 'branch_id', 'account_no']));
        $companyFields = array_diff_key($config['fields'], $bankingFields);
    @endphp
    @include('master-data.fields', ['config' => ['fields' => $companyFields]])
    <div class="wide">
        <h3>Additional banking details (optional)</h3>
        <p>Add bank details now or leave them blank and add them later.</p>
        <div class="form-grid">
            @include('master-data.fields', ['config' => ['fields' => array_replace(array_flip(['bank_id', 'branch_id', 'account_no']), $bankingFields)]])
        </div>
    </div>
@else
    @include('master-data.fields')
@endif
