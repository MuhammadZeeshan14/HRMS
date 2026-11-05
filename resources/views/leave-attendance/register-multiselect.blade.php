@php
    $selected = array_map('strval', (array)request($field, []));
    $selectedLabels = collect($items)->filter(fn($item) => in_array((string)$item['value'], $selected, true))->pluck('label')->all();
    $allLabel = $field === 'status' ? 'All statuses' : 'All authorized '.strtolower($label).' records';
    $summary = count($selectedLabels) === 1 ? $selectedLabels[0] : (count($selectedLabels) ? count($selectedLabels).' selected' : $allLabel);
@endphp
<div class="register-filter-field">
    <span id="filter-label-{{ $field }}">{{ $label }}</span>
    <details class="register-multiselect" data-register-multiselect data-all-label="{{ $allLabel }}">
        <summary aria-labelledby="filter-label-{{ $field }} filter-summary-{{ $field }}"><span id="filter-summary-{{ $field }}" data-filter-summary title="{{ implode(', ', $selectedLabels) }}">{{ $summary }}</span><span aria-hidden="true">⌄</span></summary>
        <div class="register-filter-popup">
            <input type="search" data-filter-search placeholder="Search {{ strtolower($label) }}" aria-label="Search {{ strtolower($label) }} options" autocomplete="off">
            <div class="register-filter-tools" hidden data-filter-tools><button type="button" data-filter-select-all>Select shown</button><button type="button" data-filter-clear>Clear</button><button type="button" data-filter-done>Done</button></div>
            <div class="register-filter-options" role="group" aria-labelledby="filter-label-{{ $field }}">
                @forelse($items as $item)<label data-filter-option><input type="checkbox" name="{{ $field }}[]" value="{{ $item['value'] }}" @checked(in_array((string)$item['value'], $selected, true))><span>{{ $item['label'] }}</span></label>@empty<p>No available options</p>@endforelse
            </div>
            <p data-filter-no-results hidden>No matches found.</p>
        </div>
    </details>
</div>
