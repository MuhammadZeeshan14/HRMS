@extends('layouts.app')
@section('title', 'HRLA Setup')
@section('content')
<div class="employee-breadcrumb"><a href="{{ route('la.dashboard') }}">Dashboard</a> › HRLA Setup › <strong>{{ $config['label'] }}</strong></div>
<div class="page-head"><div><span class="eyebrow">HRLA Administration</span><h1>HRLA Setup &amp; Master Data</h1><p>Maintain attendance schedules and leave rules used by HRLA.</p></div></div>
<div class="master-layout">
    <aside class="card master-nav">
        @foreach($masters as $key => $item)
            <a class="{{ $kind === $key ? 'active' : '' }}" href="{{ route('la.setup', $key) }}"><span>{{ $item['label'] }}</span><strong>{{ $counts[$key] }}</strong></a>
        @endforeach
    </aside>
    <div>
        @if($kind === 'assignments')
            @include('leave-attendance.assignment-table')
        @elseif($kind === 'policies')
            @include('leave-attendance.policy-overview')
        @else
        <section class="card">
            <div class="card-head"><h2>Add {{ str($config['label'])->singular()->lower() }}</h2></div>
            <form method="post" action="{{ route('la.setup.store', $kind) }}" class="form-grid master-form">
                @csrf
                @include('leave-attendance.setup-fields', ['record' => null])
                <div class="form-actions wide"><button class="btn primary">Save Now</button><button type="reset" class="btn">Cancel</button></div>
            </form>
        </section>
        @endif
        @if(!in_array($kind, ['assignments', 'policies']))
        <section class="card master-table">
            <div class="card-head"><h2>{{ $kind === 'assignments' ? 'Assignment history' : $config['label'] }}</h2></div>
            <form method="get" class="form-grid master-form"><label class="field-medium">Search name or subject<input name="q" value="{{ request('q') }}"></label><div class="form-actions"><button class="btn">Search</button><a class="btn" href="{{ route('la.setup', $kind) }}">Clear</a></div></form>
            <div class="master-list-scroll" role="region" aria-label="{{ $config['label'] }} records" tabindex="0">
                <table class="master-records">
                    <caption class="sr-only">{{ $config['label'] }} details and actions</caption>
                    <thead><tr><th scope="col">#</th>@foreach($config['fields'] as $label)<th scope="col">{{ $label }}</th>@endforeach<th scope="col" class="master-actions-column">Actions</th></tr></thead>
                    <tbody>
                    @forelse($records as $record)
                        <tr>
                            <td>{{ $records->firstItem() + $loop->index }}</td>
                            @foreach($config['fields'] as $field => $label)
                                <td>@include('leave-attendance.setup-cell')</td>
                            @endforeach
                            <td class="master-actions-column"><div class="master-row-actions">
                                <button type="button" class="btn" data-edit-record="edit-record-{{ $record->id }}" aria-label="Edit {{ $record->name ?? $config['label'] }}">Edit</button>
                                <form method="post" action="{{ route('la.setup.delete', [$kind, $record->id]) }}" onsubmit="return confirm('Delete this configuration? Referenced history is protected.')">
                                    @csrf @method('DELETE')<button class="btn danger" aria-label="Delete {{ $record->name ?? $config['label'] }}">Delete</button>
                                </form>
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($config['fields']) + 2 }}" class="empty">No records found.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @php
                $dialogRecords = $records->getCollection();
                if ($edit && !$dialogRecords->contains('id', $edit->id)) {
                    $dialogRecords = $dialogRecords->concat([$edit]);
                }
            @endphp
            @foreach($dialogRecords as $record)
                <dialog id="edit-record-{{ $record->id }}" class="master-edit-dialog" aria-labelledby="edit-title-{{ $record->id }}" @if(($errors->any() && (string)old('_edit_record_id') === (string)$record->id) || ($edit?->id === $record->id && !$errors->any())) data-reopen-edit @endif>
                    <div class="card-head"><h2 id="edit-title-{{ $record->id }}">Edit {{ $record->name ?? str($config['label'])->singular()->lower() }}</h2><button type="button" class="btn" data-close-edit aria-label="Close edit form">Close</button></div>
                    @if($errors->any() && (string)old('_edit_record_id') === (string)$record->id)
                        <div class="alert danger" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                    @endif
                    <form method="post" action="{{ route('la.setup.update', [$kind, $record->id]) }}" class="form-grid master-form">
                        @csrf @method('PUT')
                        <input type="hidden" name="_edit_record_id" value="{{ $record->id }}">
                        @include('leave-attendance.setup-fields')
                        <div class="form-actions wide"><button class="btn primary">Save Changes</button><button type="button" class="btn" data-close-edit>Cancel</button></div>
                    </form>
                </dialog>
            @endforeach
            <div class="pager">{{ $records->links() }}</div>
        </section>
        @endif
    </div>
</div>
<script src="{{ asset('master-data.js') }}" defer></script>
@if($kind === 'assignments')<script src="{{ asset('shift-assignments.js') }}" defer></script>@endif
@if($kind === 'holidays')<script src="{{ asset('holiday-date.js') }}" defer></script>@endif
@endsection
