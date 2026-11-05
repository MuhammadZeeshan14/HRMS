<span class="master-status" data-import-validation>{{ $committed ? ucfirst($row['outcome'] ?? (empty($row['errors']) ? 'imported' : 'failed')) : ($excluded ? 'Excluded' : (empty($row['errors']) ? 'Ready' : 'Needs correction')) }}</span>
<strong data-import-day-status>{{ $row['calculation']['status'] ?? '—' }}</strong>
@if(!empty($row['auto_format_fields']))<small class="import-format-fixed">Automatically fixed: {{ str(implode(', ', $row['auto_format_fields']))->replace('_', ' ') }}</small>@endif
@if(!empty($row['errors']))<small class="import-error">{{ count($row['errors']) }} validation {{ count($row['errors']) === 1 ? 'error' : 'errors' }}</small>@endif
<details data-import-details><summary>Errors, interpretation &amp; source</summary>
@if(!empty($row['interpretation_notes']))<small>Original import interpretation:</small>@endif
@foreach($row['interpretation_notes'] ?? [] as $note)<small class="import-interpretation">{{ $note }}</small>@endforeach
@if(count($row['source_rows'] ?? []) > 1)<small>Source rows: {{ implode(', ', $row['source_rows']) }}</small>@endif
@if(!empty($row['errors']))<ul class="import-error">@foreach($row['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
@if(!empty($row['current']))<small>Saved at validation:<br>In: {{ \App\Support\HrlaTime::stamp($row['current']['time_in']) }}<br>Out: {{ \App\Support\HrlaTime::stamp($row['current']['time_out']) }}</small>@endif
@if(!$committed)<button type="button" class="import-text-action" data-refresh-import-row>Reload current</button>@endif
<details><summary>Original spreadsheet values</summary>@foreach($row['source_data'] ?? $row['data'] as $key => $value)<small>{{ str($key)->replace('_', ' ')->title() }}: {{ in_array($key, ['time_in','time_out']) ? \App\Support\HrlaTime::stamp($value, 'Blank') : ($value ?? 'Blank') }}</small>@endforeach</details>
@if(!empty($row['edited_fields']))<small>Edited: {{ str(implode(', ', $row['edited_fields']))->replace('_', ' ') }}</small>@endif
</details>
