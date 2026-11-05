@extends('layouts.app')
@section('title', 'Company Departments')
@section('content')
<link rel="stylesheet" href="{{ asset('company-department.css') }}">
<div class="employee-breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> › Company Departments</div>
<div class="page-head"><div><span class="eyebrow">Organization</span><h1>Company Departments</h1><p>Company, department levels, designations, and employees connected by their department.</p></div></div>
<form method="get" class="card form-grid master-form">
    <label>Company<select name="company_id" required>
        @foreach($companies as $item)<option value="{{ $item->id }}" @selected($company?->id === $item->id)>{{ $item->name }}</option>@endforeach
    </select></label>
    <label>Employees<select name="staff"><option value="active" @selected($staff === 'active')>Active employees</option><option value="all" @selected($staff === 'all')>All employees</option></select></label>
    <div class="form-actions"><button class="btn primary" @disabled($companies->isEmpty())>Show department</button></div>
</form>
@if($company)
<section class="card department-chart" aria-label="Company department flowchart">
    <div class="department-actions">
        <button type="button" class="btn" data-department-expand>Expand all</button><button type="button" class="btn" data-department-collapse>Collapse all</button>
        <button type="button" class="btn" data-chart-out aria-label="Zoom out">−</button><output data-chart-zoom aria-live="polite">100%</output><button type="button" class="btn" data-chart-in aria-label="Zoom in">+</button>
        <button type="button" class="btn" data-chart-fit>Fit chart</button><button type="button" class="btn" data-chart-reset>100%</button>
    </div>
    <p class="department-help">Follow the connectors from top to bottom. Scroll to explore and use the branch buttons to show or hide levels.</p>
    <div class="department-legend"><span>Company</span><span>Department</span><span>Designation</span><span>Employee</span></div>
    <div class="department-viewport" tabindex="0" role="region" aria-label="Scrollable organization flowchart">
        <div class="department-sizer"><div class="department-canvas">
            <ul class="flow-tree"><li class="flow-node">
                <article class="flow-box flow-company"><span class="department-meta">Company</span><h2>{{ $company->name }}</h2><span>{{ $employeeCount }} {{ $staff === 'active' ? 'active employees' : 'employees' }}</span></article>
                @if($roots || $unassigned->isNotEmpty())
                <ul>
                    @foreach($roots as $key)@include('company-department.node', ['key' => $key])@endforeach
                    @if($unassigned->isNotEmpty())
                    <li class="flow-node"><article class="flow-box"><h3>Employees without a department</h3><p class="department-note">Assign a department through an authorized employee workflow to place these employees in the hierarchy.</p></article><ul>@foreach($unassigned as $employee)@include('company-department.employee')@endforeach</ul></li>
                    @endif
                </ul>
                @else
                <p class="empty">No departments have been assigned to this company.</p>
                @endif
            </li></ul>
        </div></div>
    </div>
</section>
@else
<div class="card empty">Create a company in Master Data to view its department.</div>
@endif
<script src="{{ asset('company-department.js') }}" defer></script>
@endsection
