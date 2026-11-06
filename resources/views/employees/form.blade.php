@extends('layouts.app')

@section('title', $employee->exists ? 'Edit Employee' : 'New Employee')

@section('record-strip')
<div class="record-strip"><x-icon name="id" /><span>Employee Information <strong>{{ $employee->employee_no }} {{ $employee->exists ? '– '.$employee->display_name : '' }}</strong>@if($employee->exists) | {{ $employee->department?->name }} | {{ $employee->location?->name }}@endif</span><span class="record-filled">● {{ $employee->profile_completion }}% Filled</span><span class="record-status">● {{ $employee->employeeStatus?->name ?? str($employee->employment_type ?: 'permanent')->title() }} {{ str($employee->employment_status ?: 'active')->replace('_',' ')->title() }}</span></div>
@endsection
@section('content')
@php
    $rowData = fn ($key) => old($key, $related[$key]->map(fn ($row) => (array) $row)->values()->all());
    $trainings = $rowData('trainings'); $certificationRows = $rowData('certifications'); $qualifications = $rowData('qualifications');
    $experiences = $rowData('experiences'); $relatives = $rowData('relatives'); $referenceRows = $rowData('references');
    $projects = $rowData('projects'); $salaries = $rowData('salaries'); $documents = $rowData('documents');
    $selectedDesignation = $designations->firstWhere('id', old('designation_id', $employee->designation_id));
    $designationSearch = old('designation_search', $selectedDesignation ? $selectedDesignation->name.' ('.$selectedDesignation->code.')' : '');
@endphp
<form class="employee-form" method="post" enctype="multipart/form-data" action="{{ $employee->exists ? route('employees.update', $employee) : route('employees.store') }}">
    @csrf @if($employee->exists) @method('PUT') @endif
    <div class="module-heading">Employee Management Modules</div><div class="tabs form-tabs" aria-label="Employee entry sections">
        @foreach(['employment'=>'Employee Info','training'=>'Trainings / Certifications','qualifications'=>'Qualifications','experiences'=>'Experiences','references'=>'References','projects'=>'Project Experience','salary'=>'Salary','documents'=>'Documents'] as $id => $label)
            <button type="button" class="{{ $loop->first ? 'active' : '' }}" title="{{ $label }}" data-tab="form-{{ $id }}">{{ $id === 'employment' ? 'Employee Info' : ($id === 'salary' ? 'Salary & Payroll' : $label) }}</button>
        @endforeach
    </div>

<section class="tab-panel entry-info-panel" id="form-employment">
<section class="entry-card" aria-labelledby="employment-heading">
<h2 class="entry-section-title" id="employment-heading">Employment Information</h2>
<div class="entry-grid"><div class="reference-field"><label for="employee_no">Employee number <span>*</span></label><input id="employee_no" name="employee_no" value="{{ old('employee_no', $employee->employee_no) }}" required></div>
<div class="reference-field employee-name-field"><label for="full_name">Employee Name <span>*</span></label><input id="full_name" name="full_name" value="{{ old('full_name', $employee->display_name) }}" maxlength="240" autocomplete="name" required></div>
<div class="reference-field"><label for="father_name">Father / husband name</label><input id="father_name" name="father_or_husband_name" value="{{ old('father_or_husband_name', $employee->father_or_husband_name) }}"></div>
<aside class="employee-photo-panel entry-photo">
                <span class="entry-photo-title">Photo</span><input id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" aria-label="Choose employee photo">
                <label for="photo" class="employee-photo-preview" title="Choose employee photo">
                    @if($employee->photo_path)<img src="{{ $employee->photoUrl() }}" alt="Employee photo">@else<span class="entry-photo-prompt"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 7h4l2-3h4l2 3h4v13H4z"/><circle cx="12" cy="13" r="4"/></svg><span>Choose image</span><small>PNG, JPG or WebP</small></span>@endif
                </label>
            </aside>
<div class="reference-field"><label for="date_of_joining">Date of joining <span>*</span></label><input id="date_of_joining" type="date" name="date_of_joining" value="{{ old('date_of_joining', $employee->date_of_joining?->format('Y-m-d')) }}" required></div>
<div class="reference-field"><label for="gender">Gender</label><select id="gender" name="gender"><option value="">Select</option>@foreach(['male','female','other','prefer_not_to_say'] as $value)<option value="{{ $value }}" @selected(old('gender', $employee->gender) === $value)>{{ str($value)->replace('_',' ')->title() }}</option>@endforeach</select></div>
<div class="reference-field"><label for="company_id">Company <span>*</span></label><select id="company_id" name="company_id" required><option value="">Select company</option>@foreach($companies as $item)<option value="{{ $item->id }}" @selected(old('company_id', $employee->company_id) == $item->id)>{{ $item->name }}</option>@endforeach</select></div>
<div class="reference-field"><label for="department_id">Department</label><select id="department_id" name="department_id"><option value="">Select department</option>@foreach($departments as $item)<option value="{{ $item->id }}" @selected(old('department_id', $employee->department_id) == $item->id)>{{ $departmentPaths[$item->id] ?? $item->name }}</option>@endforeach</select></div>
<div class="reference-field"><label for="designation_search">Designation</label><div class="designation-picker"><input id="designation_search" name="designation_search" type="text" list="designation-options" value="{{ $designationSearch }}" placeholder="Search designations" autocomplete="off" data-designation-search><input type="hidden" id="designation_id" name="designation_id" value="{{ old('designation_id', $employee->designation_id) }}"><datalist id="designation-options">@foreach($designations as $item)<option value="{{ $item->name }} ({{ $item->code }})" data-id="{{ $item->id }}"></option>@endforeach</datalist></div></div>
<div class="reference-field"><label for="grade_id">Grade</label><select id="grade_id" name="grade_id"><option value="">Select grade</option>@foreach($grades as $item)<option value="{{ $item->id }}" @selected(old('grade_id', $employee->grade_id) == $item->id)>{{ $item->name }}</option>@endforeach</select></div>
<div class="reference-field"><label for="employment_status">Record status</label><select id="employment_status" name="employment_status">@foreach(['active','inactive','on_leave','exited'] as $status)<option value="{{ $status }}" @selected(old('employment_status', $employee->employment_status ?: 'active') === $status)>{{ str($status)->replace('_',' ')->title() }}</option>@endforeach</select></div>
<div class="reference-field"><label for="employment_status_id">Employee status</label><input type="hidden" name="_status_source" value="hris"><input type="hidden" name="employment_type" value="{{ old('employment_type', $employee->employment_type ?: 'permanent') }}">@php($employeeStatusId = old('employment_status_id', $employee->employment_status_id ?? $employmentStatuses->first(fn ($status) => mb_strtolower(trim($status->name)) === ($employee->employment_type ?: 'permanent'))?->id))<select id="employment_status_id" name="employment_status_id" required><option value="">Select employee status</option>@foreach($employmentStatuses as $status)<option value="{{ $status->id }}" @selected((string)$employeeStatusId === (string)$status->id)>{{ $status->name }}</option>@endforeach</select></div>
<div class="reference-field entry-location-first"><label for="location_id">Location</label><select id="location_id" name="location_id"><option value="">Select location</option>@foreach($locations as $item)<option value="{{ $item->id }}" @selected(old('location_id', $employee->location_id) == $item->id)>{{ $item->name }}</option>@endforeach</select></div>
<div class="reference-field"><label for="placement_location_id">Placement location</label><select id="placement_location_id" name="placement_location_id"><option value="">Select location</option>@foreach($locations as $item)<option value="{{ $item->id }}" @selected(old('placement_location_id', $employee->placement_location_id) == $item->id)>{{ $item->name }}</option>@endforeach</select></div>
<div class="reference-field"><label for="reporting_location_id">Reporting location</label><select id="reporting_location_id" name="reporting_location_id"><option value="">Select location</option>@foreach($locations as $item)<option value="{{ $item->id }}" @selected(old('reporting_location_id', $employee->reporting_location_id) == $item->id)>{{ $item->name }}</option>@endforeach</select></div>
<div class="reference-field entry-dual entry-reporting-first"><label class="compact-check"><input type="hidden" name="dual_reporting" value="0"><input type="checkbox" name="dual_reporting" value="1" @checked(old('dual_reporting', $employee->dual_reporting))>Dual reporting</label></div>
<div class="reference-field"><label for="primary_report">Primary reporting person</label><select id="primary_report" name="primary_report_to_id"><option value="">None</option>@foreach($reportingEmployees as $person)<option value="{{ $person->id }}" @selected(old('primary_report_to_id', $employee->primary_report_to_id) == $person->id)>{{ $person->display_name }} · {{ $person->employee_no }}</option>@endforeach</select></div>
<div class="reference-field"><label for="secondary_report">Secondary reporting person</label><select id="secondary_report" name="secondary_report_to_id"><option value="">None</option>@foreach($reportingEmployees as $person)<option value="{{ $person->id }}" @selected(old('secondary_report_to_id', $employee->secondary_report_to_id) == $person->id)>{{ $person->display_name }} · {{ $person->employee_no }}</option>@endforeach</select></div>
<div class="reference-field entry-wide textarea-field"><label for="profile_description">Profile description</label><textarea id="profile_description" name="profile_description" rows="3">{{ old('profile_description', $employee->profile_description) }}</textarea></div>
<div class="reference-field entry-wide textarea-field"><label for="job_description">Job description</label><textarea id="job_description" name="job_description" rows="3">{{ old('job_description', $employee->job_description) }}</textarea></div>
</div>
</section>
<section class="entry-card" aria-label="Personal information">
<h2 class="entry-section-title">Personal Information</h2>
<div class="entry-grid"><div class="reference-field"><label for="email">Email</label><input id="email" type="email" name="email" value="{{ old('email', $employee->email) }}"></div>
<div class="reference-field"><label for="phone">Phone</label><input id="phone" name="phone" value="{{ old('phone', $employee->phone) }}"></div>
<div class="reference-field entry-address textarea-field"><label for="address">Address</label><textarea id="address" name="address" rows="2">{{ old('address', $employee->address) }}</textarea></div>
<div class="reference-field"><label for="cnic">CNIC</label><input id="cnic" name="cnic" value="{{ old('cnic', $employee->cnic) }}"></div>
<div class="reference-field"><label for="passport_no">Passport number</label><input id="passport_no" name="passport_no" value="{{ old('passport_no', $employee->passport_no) }}"></div>
<div class="reference-field"><label for="ntn">NTN</label><input id="ntn" name="ntn" value="{{ old('ntn', $employee->ntn) }}"></div>
<div class="reference-field"><label for="eobi_no">EOBI number</label><input id="eobi_no" name="eobi_no" value="{{ old('eobi_no', $employee->eobi_no) }}"></div>
<div class="reference-field"><label for="date_of_birth">Date of birth</label><input id="date_of_birth" type="date" max="{{ today()->subDay()->toDateString() }}" name="date_of_birth" value="{{ old('date_of_birth', $employee->date_of_birth?->format('Y-m-d')) }}"></div>
<div class="reference-field"><label for="place_of_birth">Place of birth</label><input id="place_of_birth" name="place_of_birth" value="{{ old('place_of_birth', $employee->place_of_birth) }}"></div>
<div class="reference-field"><label for="religion_id">Religion</label><select id="religion_id" name="religion_id"><option value="">Select religion</option>@foreach($religions as $item)<option value="{{ $item->id }}" @selected(old('religion_id', $employee->religion_id) == $item->id)>{{ $item->name }}</option>@endforeach</select></div></div>
</section>
<section class="entry-card entry-languages" aria-label="Languages"><h2 class="entry-section-title">Languages</h2>
        <div class="language-field">
            <button type="button" class="btn" data-open-language-dialog>Add language</button>
            <span data-language-summary aria-live="polite">No languages selected</span>
            <small>Select one or more languages.</small>
        </div>
        <dialog class="employee-picker-dialog" data-language-dialog aria-labelledby="language-dialog-title">
            <div class="employee-picker-content">
                <h2 id="language-dialog-title">Add Languages</h2>
                <label class="language-search-label">Find a language<input type="search" data-language-search placeholder="Search languages" autocomplete="off"></label>
                <div class="language-options">
                    @forelse($languages as $language)
                        <label class="language-option"><input type="checkbox" name="language_ids[]" value="{{ $language->id }}" @checked(in_array($language->id, old('language_ids', $languageIds)))><span>{{ $language->name }}</span></label>
                    @empty
                        <p>No languages available.</p>
                    @endforelse
                </div>
                <p data-language-no-results hidden>No matching languages.</p>
                <div class="dialog-actions"><button type="button" class="btn" data-close-language-dialog>Done</button></div>
            </div>
        </dialog></section>
@include('employees.partials.relatives-fields')
@include('employees.partials.tab-actions', ['nextSection' => 'form-training', 'nextLabel' => 'Next: Trainings / Certifications'])
</section>

<section class="tab-panel card entry-record-panel" id="form-training" hidden>
        <div class="section-heading"><div><h2>Trainings</h2><p>Add completed or planned training records.</p></div><button type="button" class="btn" data-add="trainings">Add training</button></div>
        <div class="repeater" data-repeater="trainings">
            @foreach($trainings as $i => $row)
                <div class="repeater-row"><input type="hidden" name="trainings[{{ $i }}][id]" value="{{ data_get($row,'id') }}"><input type="hidden" class="delete-flag" name="trainings[{{ $i }}][_delete]" value="0">
                    <div class="form-grid"><label>Training type<select name="trainings[{{ $i }}][training_type_id]"><option value="">Select</option>@foreach($trainingTypes as $item)<option value="{{ $item->id }}" @selected(data_get($row,'training_type_id') == $item->id)>{{ $item->name }}</option>@endforeach</select></label><label>Title<input name="trainings[{{ $i }}][title]" value="{{ data_get($row,'title') }}"></label><label>Provider<input name="trainings[{{ $i }}][provider]" value="{{ data_get($row,'provider') }}"></label><label>Starts on<input type="date" name="trainings[{{ $i }}][starts_on]" value="{{ data_get($row,'starts_on') }}"></label><label>Ends on<input type="date" name="trainings[{{ $i }}][ends_on]" value="{{ data_get($row,'ends_on') }}"></label><label class="wide">Notes<textarea name="trainings[{{ $i }}][notes]">{{ data_get($row,'notes') }}</textarea></label></div><button type="button" class="remove-row">Remove</button>
                </div>
            @endforeach
        </div>
        <template data-template="trainings"><div class="repeater-row"><input type="hidden" name="trainings[__INDEX__][_delete]" class="delete-flag" value="0"><div class="form-grid"><label>Training type<select name="trainings[__INDEX__][training_type_id]"><option value="">Select</option>@foreach($trainingTypes as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select></label><label>Title<input name="trainings[__INDEX__][title]"></label><label>Provider<input name="trainings[__INDEX__][provider]"></label><label>Starts on<input type="date" name="trainings[__INDEX__][starts_on]"></label><label>Ends on<input type="date" name="trainings[__INDEX__][ends_on]"></label><label class="wide">Notes<textarea name="trainings[__INDEX__][notes]"></textarea></label></div><button type="button" class="remove-row">Remove</button></div></template>

        <div class="section-heading divided"><div><h2>Certifications</h2><p>Track credentials and expiry dates.</p></div><button type="button" class="btn" data-add="certifications">Add certification</button></div>
        <div class="repeater" data-repeater="certifications">@foreach($certificationRows as $i => $row)<div class="repeater-row"><input type="hidden" name="certifications[{{ $i }}][id]" value="{{ data_get($row,'id') }}"><input type="hidden" class="delete-flag" name="certifications[{{ $i }}][_delete]" value="0"><div class="form-grid"><label>Certification<select name="certifications[{{ $i }}][certification_id]"><option value="">Select</option>@foreach($certificationOptions as $item)<option value="{{ $item->id }}" @selected(data_get($row,'certification_id') == $item->id)>{{ $item->name }}</option>@endforeach</select></label><label>Title<input name="certifications[{{ $i }}][title]" value="{{ data_get($row,'title') }}"></label><label>Credential number<input name="certifications[{{ $i }}][credential_no]" value="{{ data_get($row,'credential_no') }}"></label><label>Issued on<input type="date" name="certifications[{{ $i }}][issued_on]" value="{{ data_get($row,'issued_on') }}"></label><label>Expires on<input type="date" name="certifications[{{ $i }}][expires_on]" value="{{ data_get($row,'expires_on') }}"></label></div><button type="button" class="remove-row">Remove</button></div>@endforeach</div>
        <template data-template="certifications"><div class="repeater-row"><input type="hidden" name="certifications[__INDEX__][_delete]" class="delete-flag" value="0"><div class="form-grid"><label>Certification<select name="certifications[__INDEX__][certification_id]"><option value="">Select</option>@foreach($certificationOptions as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select></label><label>Title<input name="certifications[__INDEX__][title]"></label><label>Credential number<input name="certifications[__INDEX__][credential_no]"></label><label>Issued on<input type="date" name="certifications[__INDEX__][issued_on]"></label><label>Expires on<input type="date" name="certifications[__INDEX__][expires_on]"></label></div><button type="button" class="remove-row">Remove</button></div></template>

    @include('employees.partials.tab-actions', ['nextSection' => 'form-qualifications', 'nextLabel' => 'Next: Qualifications'])
</section>

    @include('employees.partials.repeatable-sections', compact('qualifications','experiences','relatives','referenceRows','projects','salaries','documents','documentTypes','leavingReasons'))

    <div class="sticky-actions"><span>{{ $employee->display_name ?: 'New Employee' }} {{ $employee->employee_no ? "(".$employee->employee_no.")" : "" }} &nbsp; | &nbsp; Press <kbd>Ctrl+S</kbd> to save</span><div><a class="btn" href="{{ $employee->exists ? route('employees.show', $employee) : route('employees.index') }}">Cancel</a><button class="btn primary"><x-icon name="check" />{{ $employee->exists ? 'Save changes' : 'Create employee' }}</button></div></div>
</form><p id="reporting-help" role="status"></p>
<script>
(() => {
const company = document.getElementById('company_id'), department = document.getElementById('department_id');
let generation = 0;
async function refreshReporting() {
 const token = ++generation, primary = document.getElementById('primary_report'), secondary = document.getElementById('secondary_report');
 const previous = [primary.value,secondary.value];
 [primary,secondary].forEach(select=>{select.replaceChildren(new Option('Select department first','')); select.disabled=true;});
 if(!company.value || !department.value)return;
 try {
 const query = new URLSearchParams({company_id:company.value,department_id:department.value,employee_id:{{ $employee->id ?? 'null' }} || ''});
 const response=await fetch(@json(route('reporting.candidates'))+'?'+query,{headers:{Accept:'application/json'}});
 if(!response.ok)throw new Error('Unable to load eligible reporting persons.');
 const result=await response.json();if(token!==generation)return;
 [primary,secondary].forEach((select,i)=>{select.replaceChildren(new Option(result.root?'None (root department)':'Choose reporting Admin','')); result.employees.forEach(e=>select.add(new Option(e.name,e.id)));select.value=previous[i];select.disabled=false;});
 document.getElementById('reporting-help').textContent=result.root?'Root departments have no reporting person.':result.employees.length?'Choose an Admin from the actual ancestor departments.':'No eligible reporting Admin exists. Ask a Level 1 employee to assign an active Admin in an ancestor department.';
 } catch(error) {document.getElementById('reporting-help').textContent=error.message;}
}
company.addEventListener('change',refreshReporting);department.addEventListener('change',refreshReporting);refreshReporting();
})();
</script><script src="{{ asset('employee-redesign.js') }}" defer></script><script src="{{ asset('employee-record-tables.js') }}" defer></script>
@endsection
