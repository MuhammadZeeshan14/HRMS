@php($node = $nodes[$key])
<li class="flow-node">
    <article class="flow-box flow-level">
        <span class="department-meta">{{ $node['kind'] }}</span>
        <h3>{{ $node['name'] }}</h3>
        <span class="department-meta">@if($node['level_number'] !== null)Level {{ $node['level_number'] }}@endif</span>
        @if($node['note'])<p class="department-note">{{ $node['note'] }}</p>@endif
        @if($node['children'] || $node['employees']->isNotEmpty())<button type="button" class="flow-toggle" aria-expanded="true" aria-controls="branch-{{ $key }}" data-branch-toggle>Hide branch</button>@endif
    </article>
    @if($node['children'] || $node['employees']->isNotEmpty())
        <ul id="branch-{{ $key }}">
            @foreach($node['employees'] as $employee)@include('company-department.employee')@endforeach
            @foreach($node['children'] as $childKey)@include('company-department.node', ['key' => $childKey])@endforeach
        </ul>
    @endif
</li>
