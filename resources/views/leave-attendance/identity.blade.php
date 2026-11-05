@if($employee)
<dl class="card la-identity">@foreach(['Code'=>$employee->employee_no,'Name'=>$employee->display_name,'Department'=>$employee->department?->name,'Designation'=>$employee->designation?->name,'Location'=>$employee->location?->name] as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value ?? '—' }}</dd></div>@endforeach</dl>
@endif
