@php
    $useOld = session()->hasOldInput() && (string) old('_edit_record_id', '') === (string) ($record?->id ?? '');
    if ($fixedPolicyContext ?? false) {
        $useOld = $useOld && (string)old('leave_type_id') === (string)$policyType->id && (string)old('employment_status_id') === (string)$selectedStatus->id;
    }
    $value = fn ($field, $default = null) => $useOld ? old($field, $default) : $default;
@endphp
@if(in_array($kind,['shifts','holidays','types']))<label class="field-long">Name<input name="name" value="{{ $value('name',$record?->name) }}" required maxlength="255"></label>@endif
@if($kind==='shifts')
<div class="field-short hrla-clock-field"><span>Start Time</span><x-hrla-clock name="start_time" :value="$value('start_time',$record?->start_time)" label="Start Time" :required="true" :use-old="$useOld" /></div><div class="field-short hrla-clock-field"><span>End Time</span><x-hrla-clock name="end_time" :value="$value('end_time',$record?->end_time)" label="End Time" :required="true" :use-old="$useOld" /></div>
@php($overnightValue=$value('overnight',$record ? (int)$record->overnight : null))
<label class="field-medium">End date behavior<select name="overnight" required><option value="">Select behavior</option><option value="0" @selected((string)$overnightValue==='0')>Same calendar day</option><option value="1" @selected((string)$overnightValue==='1')>Next calendar day (overnight)</option></select></label><p class="wide la-note">Names may repeat; each shift has a stable ID. Committed attendance retains the original shift times.</p>
@elseif($kind==='holidays')
<div class="field-short holiday-date-field" data-holiday-date><label for="holiday-date-{{ $record?->id ?? 'new' }}">Date (day/month)</label><div class="holiday-date-control"><input id="holiday-date-{{ $record?->id ?? 'new' }}" name="holiday_date" data-month-day inputmode="numeric" placeholder="DD/MM" pattern="[0-9]{2}/[0-9]{2}" maxlength="5" value="{{ $value('holiday_date', $record ? sprintf('%02d/%02d', $record->day, $record->month) : '') }}" required aria-label="Holiday date, day and month"><button type="button" class="holiday-date-toggle" aria-label="Choose holiday date" aria-expanded="false"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg></button></div></div>
<p class="wide la-note">Holidays repeat each year on this day and month. February 29 applies only in leap years. Saturdays and Sundays are automatically days off. Calendar changes intersecting approved leave require reconciliation and are blocked.</p>
@elseif($kind==='types')
<label class="field-short">Code<input name="code" value="{{ $value('code',$record?->code) }}" required maxlength="30"></label><label class="field-medium">Pay treatment (optional)<select name="pay_treatment"><option value="">Unspecified</option>@foreach(['paid','unpaid'] as $v)<option value="{{ $v }}" @selected($value('pay_treatment',$record?->pay_treatment)===$v)>{{ ucfirst($v) }}</option>@endforeach</select></label>
<label class="wide">Description<textarea name="description" maxlength="2000">{{ $value('description',$record?->description) }}</textarea></label>
@elseif($kind==='assignments')
<label class="field-medium">Employee<select name="employee_id" required><option value="">Select employee</option>@foreach($options['employees'] as $e)<option value="{{ $e->id }}" @selected($value('employee_id',$record?->employee_id)==$e->id)>{{ $e->employee_no }} · {{ $e->display_name }}</option>@endforeach</select></label>
<label class="field-medium">Shift<select name="shift_id" required><option value="">Select configured shift</option>@foreach($shifts as $s)<option value="{{ $s->id }}" @selected($value('shift_id',$record?->shift_id)==$s->id)>#{{ $s->id }} · {{ $s->name }} · {{ \App\Support\HrlaTime::clock($s->start_time) }}–{{ \App\Support\HrlaTime::clock($s->end_time) }}{{ $s->overnight?' (next day)':'' }}</option>@endforeach</select></label>
<label class="field-short">Effective From<input type="date" name="starts_on" value="{{ $value('starts_on',$record?->starts_on) }}" required></label><label class="field-short">Effective Through (inclusive, optional)<input type="date" name="ends_on" value="{{ $value('ends_on',$record?->ends_on) }}"></label>
<p class="wide la-note">Assignments may not overlap. End an existing assignment before adding its replacement. Existing committed attendance uses its saved shift snapshot.</p>
@elseif($kind==='policies')
@if($fixedPolicyContext ?? false)
<input type="hidden" name="employment_status_id" value="{{ $selectedStatus->id }}"><input type="hidden" name="leave_type_id" value="{{ $policyType->id }}">
<div class="field-medium"><span>Employee status</span><p><strong>{{ $selectedStatus->name }}</strong></p></div><div class="field-medium"><span>Leave type</span><p><strong>{{ $policyType->name }}</strong></p></div>
@else
<label class="field-medium">Employee status<select name="employment_status_id" required><option value="">Select HRIS employee status</option>@foreach($employeeStatuses as $status)<option value="{{ $status->id }}" @selected((string)$value('employment_status_id',$record?->employment_status_id)===(string)$status->id)>{{ $status->name }}{{ $status->status === 'inactive' ? ' (inactive)' : '' }}</option>@endforeach</select><a href="{{ route('master-data.index', 'employment_statuses') }}">Manage statuses</a></label>
<label class="field-medium">Leave type<select name="leave_type_id" required><option value="">Select configured leave type</option>@foreach($types as $t)<option value="{{ $t->id }}" @selected($value('leave_type_id',$record?->leave_type_id)==$t->id)>{{ $t->code }} · {{ $t->name }}</option>@endforeach</select></label>
@endif
<label class="field-medium">Allowance state<select name="allowance_kind" required><option value="">Select state</option>@foreach(['numeric'=>'Numeric (including explicit zero)','as_needed'=>'As needed — approvals inactive','unspecified'=>'Unspecified'] as $key=>$v)<option value="{{ $key }}" @selected($value('allowance_kind',$record?->allowance_kind)===$key)>{{ $v }}</option>@endforeach</select></label>
<label class="field-short">Numeric allowance<input type="number" min="0" max="99999" step="0.5" name="allowance" value="{{ $value('allowance',$record?->allowance) }}"></label>
<p class="wide la-note">Numeric allowances apply each calendar year, January–December. Approved leave is deducted automatically; the allowance resets each January.</p>
<label class="wide">Policy notes<textarea name="notes" maxlength="2000">{{ $value('notes',$record?->notes) }}</textarea></label><p class="wide la-note"></p>
@endif
