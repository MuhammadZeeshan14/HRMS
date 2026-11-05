@extends('layouts.app')
@section('title', 'HRIS Setup')
@section('content')
<div class="employee-breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> › HRIS Setup › <strong>{{ $config['label'] }}</strong></div>
<div class="page-head"><div><span class="eyebrow">HRIS Administration</span><h1>HRIS Setup &amp; Master Data</h1><p>Maintain setup values and documents used by HRIS.</p></div></div>
<div class="master-layout">
    <aside class="card master-nav">
        @foreach($masters as $key => $item)
            <a class="{{ $master === $key ? 'active' : '' }}" href="{{ route('master-data.index', $key) }}"><span>{{ $item['label'] }}</span><strong>{{ $counts[$key] }}</strong></a>
        @endforeach
    </aside>
    <div>
        <section class="card">
            <div class="card-head"><h2>Add {{ str($config['label'])->singular()->lower() }}</h2></div>
            <form method="post" enctype="multipart/form-data" action="{{ route('master-data.store', $master) }}" class="form-grid master-form">
                @csrf
                @include('master-data.form-fields', ['record' => null])
                <div class="form-actions wide"><button class="btn primary">Save Now</button><button type="reset" class="btn">Cancel</button></div>
            </form>
        </section>
        <section class="card master-table">
            <div class="card-head"><h2>{{ $config['label'] }}</h2></div>
            <form method="get" class="form-grid master-form"><label>Search name or subject<input name="q" value="{{ request('q') }}"></label><div class="form-actions"><button class="btn">Search</button><a class="btn" href="{{ route('master-data.index', $master) }}">Clear</a></div></form>
            <div class="master-list-scroll" role="region" aria-label="{{ $config['label'] }} records" tabindex="0">
                <table class="master-records">
                    <caption class="sr-only">{{ $config['label'] }} details and actions</caption>
                    <thead><tr><th scope="col">#</th>@foreach($config['fields'] as $meta)<th scope="col">{{ $meta['label'] }}</th>@endforeach<th scope="col" class="master-actions-column">Actions</th></tr></thead>
                    <tbody>
                    @forelse($records as $record)
                        <tr>
                            <td>{{ $records->firstItem() + $loop->index }}</td>
                            @foreach($config['fields'] as $field => $meta)
                                <td>@include('master-data.cell')</td>
                            @endforeach
                            <td class="master-actions-column"><div class="master-row-actions">
                                <button type="button" class="btn" data-edit-record="edit-record-{{ $record->id }}" aria-label="Edit {{ $record->name ?? $record->title }}">Edit</button>
                                <form method="post" action="{{ route('master-data.destroy', [$master, $record->id]) }}" onsubmit="return confirm('Delete this record?')">
                                    @csrf @method('DELETE')<button class="btn danger" aria-label="Delete {{ $record->name ?? $record->title }}">Delete</button>
                                </form>
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($config['fields']) + 2 }}" class="empty">No records found.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @foreach($records as $record)
                <dialog id="edit-record-{{ $record->id }}" class="master-edit-dialog" aria-labelledby="edit-title-{{ $record->id }}" @if($errors->any() && (string)old('_edit_record_id') === (string)$record->id) data-reopen-edit @endif>
                    <div class="card-head"><h2 id="edit-title-{{ $record->id }}">Edit {{ $record->name ?? $record->title }}</h2><button type="button" class="btn" data-close-edit aria-label="Close edit form">Close</button></div>
                    @if($errors->any() && (string)old('_edit_record_id') === (string)$record->id)
                        <div class="alert danger" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                    @endif
                    <form method="post" enctype="multipart/form-data" action="{{ route('master-data.update', [$master, $record->id]) }}" class="form-grid master-form">
                        @csrf @method('PUT')
                        <input type="hidden" name="_edit_record_id" value="{{ $record->id }}">
                        @include('master-data.form-fields')
                        <div class="form-actions wide"><button class="btn primary">Save Changes</button><button type="button" class="btn" data-close-edit>Cancel</button></div>
                    </form>
                </dialog>
            @endforeach
            <div class="pager">{{ $records->links() }}</div>
        </section>
    </div>
</div>
<script src="{{ asset('master-data.js') }}" defer></script>
@if($master === 'departments')
<script>
document.querySelectorAll('[data-department-level]').forEach(level => {
    const form = level.closest('form');
    const parent = form.querySelector('[name="parent_department_id"]');
    const updateLevel = () => { level.value = parent.value ? Number(parent.selectedOptions[0].dataset.level) + 1 : 1; };
    parent.addEventListener('change', updateLevel);
    form.addEventListener('reset', () => setTimeout(updateLevel, 0));
    updateLevel();
});
</script>
@endif
@endsection
