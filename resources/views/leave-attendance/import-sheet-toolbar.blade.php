<div class="import-sheet-toolbar" data-sheet-toolbar>
    <div class="sheet-formula-bar"><input data-sheet-address value="A2" aria-label="Selected cell" readonly><span aria-hidden="true">ƒx</span><input data-sheet-formula aria-label="Selected cell value" placeholder="Select a cell" readonly><div class="sheet-formula-actions"><button class="btn" type="button" data-sheet-edit>Edit cell</button><button type="button" class="btn" data-sheet-undo disabled>Undo</button><button type="button" class="btn" data-sheet-redo disabled>Redo</button></div></div>
    <div class="sheet-selection-line"><strong data-sheet-selection>Select a cell</strong><span>Double-click or Enter to edit · Shift + click selects a range · Ctrl+C / Ctrl+X / Ctrl+V</span></div>
    <details class="sheet-column-tools"><summary>Move, copy or swap columns</summary><div class="sheet-toolbar-line">
        <label>Action<select data-sheet-action><option value="move">Move column</option><option value="copy">Copy column</option><option value="swap">Swap columns</option><option value="source">Fill from uploaded column</option></select></label>
        <label data-sheet-mapped-source>From<select data-sheet-from>@foreach(['employee_no'=>'A · Employee','shift_date'=>'B · Attendance date','time_in'=>'C · Check-in','time_out'=>'D · Check-out'] as $field=>$name)<option value="{{ $field }}" @selected($field==='time_in')>{{ $name }}</option>@endforeach</select></label>
        <label data-sheet-upload-source hidden>Uploaded column<select data-sheet-source>@foreach($batch->headers as $index=>$name)<option value="{{ $index }}">{{ $index+1 }} · {{ $name }}</option>@endforeach</select></label>
        <label>To<select data-sheet-to>@foreach(['employee_no'=>'A · Employee','shift_date'=>'B · Attendance date','time_in'=>'C · Check-in','time_out'=>'D · Check-out'] as $field=>$name)<option value="{{ $field }}" @selected($field==='time_out')>{{ $name }}</option>@endforeach</select></label>
        <label class="sheet-replace"><input type="checkbox" data-sheet-replace> Replace existing values</label><button type="button" class="btn" data-sheet-apply>Apply to included rows on this page</button>
    </div></details>
    <p data-sheet-message role="status" aria-live="polite">Changes affect this preview. Validate before saving or changing pages.</p>
</div>
@php($sourceNumbers = collect($reviewRows->items())->pluck('row')->all())
<script type="application/json" data-sheet-source-rows>@json(collect($batch->rows)->whereIn('row', $sourceNumbers)->values())</script>
