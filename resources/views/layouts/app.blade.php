<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title','HRIS') - Ahamson HR</title>
@foreach(['hrms','hris-only','responsive-fixes','ahamson-theme','employee-info-layout','employee-redesign','app-theme','compact-forms','module-navigation'] as $stylesheet)<link rel="stylesheet" href="{{ asset($stylesheet.'.css') }}">@endforeach
@if(request()->routeIs('employees.create','employees.edit'))<link rel="stylesheet" href="{{ asset('employee-entry-refresh.css') }}">@endif
@if(request()->routeIs('la.*'))<link rel="stylesheet" href="{{ asset('leave-attendance.css') }}"><script src="{{ asset('hrla-clock.js') }}" defer></script>@endif
@if(request()->routeIs('la.import*'))<link rel="stylesheet" href="{{ asset('attendance-import.css') }}">@endif
<link rel="stylesheet" href="{{ asset('shared-form-design.css') }}">
</head>
@php($isHrla = request()->routeIs('la.*'))
<body class="app-workspace employee-workspace form-ui-theme {{ request()->routeIs('employees.create','employees.edit') ? 'employee-entry-page' : '' }}">
<div class="app-shell"><aside class="sidebar">
<a class="brand" href="{{ route($isHrla ? 'la.dashboard' : 'dashboard') }}"><img class="brand-logo" src="{{ asset('ahamson-logo.png') }}" alt="Ahamson company logo"><div class="brand-name">{{ $isHrla ? 'HRLA' : 'HRIS' }}<br>{{ $isHrla ? 'Leave & Attendance' : 'Human Resources' }}</div><x-icon name="menu" /></a>
<nav aria-label="{{ $isHrla ? 'HRLA' : 'HRIS' }} navigation">
@if($isHrla)
@include('leave-attendance.sidebar')
@else
<p>Overview</p><a class="{{ request()->routeIs('dashboard')?'active':'' }}" href="{{ route('dashboard') }}"><x-icon name="dashboard" />Dashboard</a>
@if(auth()->user()->hasRole('admin','hr_manager'))<p>Workforce Management</p><a class="nav-parent" href="{{ route('employees.index') }}"><x-icon name="users" />Employees<x-icon name="down" /></a>
<div class="employee-subnav"><a class="{{ request()->routeIs('employees.index') ? 'active' : '' }}" href="{{ route('employees.index') }}">Directory</a>@can('create',App\Models\Employee::class)<a class="{{ request()->routeIs('employees.create','employees.edit') ? 'active' : '' }}" href="{{ route('employees.create') }}">Create Employee</a>@endcan</div>
@endif
@if(auth()->user()->hasRole('admin','hr_manager'))<a class="{{ request()->routeIs('company-department.*') ? 'active' : '' }}" href="{{ route('company-department.index') }}"><x-icon name="users" />Company Departments</a>@if(app(\App\Services\EmployeeAccess::class)->configure(auth()->user()))<p>Configuration</p><a class="nav-parent {{ request()->routeIs('master-data.*')?'active':'' }}" href="{{ route('master-data.index') }}"><x-icon name="database" />HRIS Setup<x-icon name="down" /></a>
<div class="employee-subnav">@foreach(array_slice(config('hris_setup'), 0, 5, true) as $key=>$item)<a class="{{ (request()->route('master') ?? array_key_first(config('hris_setup'))) === $key ? 'active' : '' }}" href="{{ route('master-data.index',$key) }}">{{ $item['label'] }}</a>@endforeach</div>@endif @endif
@endif
<a href="{{ route('account.show') }}">My Account</a>@if(app(\App\Services\EmployeeAccess::class)->levelOne(auth()->user()))<a href="{{ route('access.index') }}">Admin Privileges</a>@endif</nav><div class="sidebar-foot"><span class="brand-dot"></span><span>Core v2.4.0 (PK)</span><x-icon name="cloud" /></div></aside>
<main><header class="topbar"><button class="menu" type="button" aria-label="Toggle navigation" onclick="document.body.classList.toggle('nav-open')">☰</button><nav class="module-switcher" aria-label="System modules"><a href="{{ route('dashboard') }}" class="{{ !$isHrla ? 'active' : '' }}" @if(!$isHrla) aria-current="page" @endif title="Human Resources Information System">HRIS</a><a href="{{ route('la.dashboard') }}" class="{{ $isHrla ? 'active' : '' }}" @if($isHrla) aria-current="page" @endif title="Leave &amp; Attendance">HRLA</a></nav>@if(request()->routeIs('employees.create','employees.edit'))<div class="entry-breadcrumb"><span>HRMS</span><span>/</span><span>Workforce</span><span>/</span><strong>Employee Information</strong></div>@endif<div class="topbar-account"><a class="header-bell" href="{{ route('account.show') }}" aria-label="My Account and notifications"><x-icon name="bell" />{{ auth()->user()->unreadNotifications()->count() }}</a><a href="{{ route('account.show') }}">My Account</a><div class="account-avatar">{{ strtoupper(substr(auth()->user()->name,0,1).substr(strrchr(auth()->user()->name,' ') ?: '',1,1)) }}</div><div class="account-name"><strong>{{ auth()->user()->name }}</strong><small>{{ app(\App\Services\EmployeeAccess::class)->admin(auth()->user()) ? 'Admin' : 'Employee' }}</small></div><form method="post" action="{{ route('logout') }}">@csrf<button type="submit" class="signout-button" aria-label="Sign out"><x-icon name="logout" /></button></form></div></header>
@yield('record-strip')
<div class="content">@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif @if(session('validation_warnings'))<div class="alert"><strong>Please review:</strong><ul>@foreach(session('validation_warnings') as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>@endif @if($errors->any())<div class="alert danger"><strong>Please fix the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')</div></main></div><script>
document.querySelectorAll('[data-tab]').forEach(button=>button.addEventListener('click',()=>{const tabs=button.closest('.tabs');tabs.querySelectorAll('[data-tab]').forEach(item=>item.classList.remove('active'));document.querySelectorAll('.tab-panel').forEach(panel=>panel.hidden=true);button.classList.add('active');document.getElementById(button.dataset.tab).hidden=false;}));
document.querySelectorAll('[data-add]').forEach(button=>button.addEventListener('click',()=>{const key=button.dataset.add;const template=document.querySelector(`template[data-template="${key}"]`);const target=document.querySelector(`[data-repeater="${key}"]`);const index=Date.now().toString()+Math.floor(Math.random()*1000);target.insertAdjacentHTML('beforeend',template.innerHTML.replaceAll('__INDEX__',index));}));
document.addEventListener('click',event=>{const button=event.target.closest('.remove-row');if(!button)return;const row=button.closest('.repeater-row');const id=row.querySelector('input[name$="[id]"]');if(id&&id.value){row.querySelector('.delete-flag').value='1';row.hidden=true;}else{row.remove();}});
</script></body></html>



