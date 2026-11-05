@if(!app(\App\Services\EmployeeAccess::class)->admin(auth()->user()))
<p>Overview</p>
<a href="{{ route('la.dashboard') }}"><x-icon name="dashboard" />HRLA Dashboard</a>
<p>My attendance</p>
<a class="{{ request()->routeIs('la.employee.today') ? 'active' : '' }}" href="{{ route('la.employee.today') }}"><x-icon name="pin" />Attendance</a>
<a class="{{ request()->routeIs('la.attendance.form') ? 'active' : '' }}" href="{{ route('la.attendance.form') }}"><x-icon name="edit" />Edit / Mark Attendance</a>
<a class="{{ request()->routeIs('la.employee.history','la.history') ? 'active' : '' }}" href="{{ route('la.employee.history') }}"><x-icon name="list" />Attendance History</a>
<p>My leave</p>
<a href="{{ route('la.leave.form') }}">Apply for Leave</a>
<a href="{{ route('la.leaves') }}">Leave Requests</a>
<a href="{{ route('la.balances') }}">My Leave Balances</a>
@else
<p>Overview</p>
<a class="{{ request()->routeIs('la.dashboard') ? 'active' : '' }}" href="{{ route('la.dashboard') }}"><x-icon name="dashboard" />HRLA Dashboard</a>
@php($hrlaAccess = app(\App\Services\LeaveAttendance\Access::class))
<p>Attendance</p>
<a class="{{ request()->routeIs('la.register','la.history') || (request()->routeIs('la.attendance.form') && !request()->boolean('direct')) ? 'active' : '' }}" href="{{ route('la.register') }}"><x-icon name="list" />Attendance Records</a>
@if($hrlaAccess->hr(auth()->user()))
<a class="{{ request()->routeIs('la.import','la.import.*') ? 'active' : '' }}" href="{{ route('la.import') }}"><x-icon name="upload" />Attendance Upload</a>
@endif
@if($hrlaAccess->hr(auth()->user()) || $hrlaAccess->employees(auth()->user(), false)->whereKeyNot(auth()->user()->employee?->id ?? 0)->exists())
<a class="{{ request()->routeIs('la.attendance.form') && request()->boolean('direct') ? 'active' : '' }}" href="{{ route('la.attendance.form',['direct' => 1]) }}"><x-icon name="edit" />Manual Entry</a>
@endif
@if(app(\App\Services\LeaveAttendance\Access::class)->hr(auth()->user()))
<p>Configuration</p>
<a class="{{ request()->routeIs('la.setup','la.setup.overview') ? 'active' : '' }}" href="{{ route('la.setup.overview') }}"><x-icon name="database" />HRLA Setup</a>
<div class="employee-subnav">
@foreach(config('hrla_setup') as $key=>$item)
@if($key === 'assignments' || app(\App\Services\EmployeeAccess::class)->configure(auth()->user()))
<a class="{{ request()->routeIs('la.setup','la.setup.overview') && (request()->route('kind') ?? 'shifts') === $key ? 'active' : '' }}" href="{{ route('la.setup',$key) }}">{{ $item['label'] }}</a>
@endif
@endforeach
</div>
@endif

<a href="{{ route('la.attendance.form') }}">{{ $hrlaAccess->hr(auth()->user()) ? 'Attendance Entry' : 'Submit Attendance' }}</a>
<a href="{{ route('la.corrections') }}">Attendance Requests</a>
<a href="{{ route('la.leave.form') }}">Apply for Leave</a>
<a href="{{ route('la.leaves') }}">Leave Requests</a>
<a href="{{ route('la.balances') }}">My Leave Balances</a>

@endif
