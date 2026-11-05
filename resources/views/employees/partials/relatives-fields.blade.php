<section class="employee-subsection" aria-label="Relatives and dependants">
    <div class="section-heading"><div><h2>Relatives</h2><p>Maintain family and dependant information.</p></div><button type="button" class="btn" data-open-relative-dialog>Add relative</button></div>
    <div class="repeater" data-repeater="relatives">@foreach($relatives as $i=>$row)<div class="repeater-row"><input type="hidden" name="relatives[{{ $i }}][id]" value="{{ data_get($row,'id') }}"><input type="hidden" class="delete-flag" name="relatives[{{ $i }}][_delete]" value="0"><div class="form-grid"><label>Relationship<input name="relatives[{{ $i }}][relationship]" value="{{ data_get($row,'relationship') }}"></label><label>Name<input name="relatives[{{ $i }}][name]" value="{{ data_get($row,'name') }}"></label><label>Phone<input name="relatives[{{ $i }}][phone]" value="{{ data_get($row,'phone') }}"></label><label>Date of birth<input type="date" name="relatives[{{ $i }}][date_of_birth]" value="{{ data_get($row,'date_of_birth') }}"></label><label class="check-field"><input type="hidden" name="relatives[{{ $i }}][is_dependent]" value="0"><input type="checkbox" name="relatives[{{ $i }}][is_dependent]" value="1" @checked(data_get($row,'is_dependent'))> Dependant</label></div><button type="button" class="remove-row">Remove</button></div>@endforeach</div>
    <template data-template="relatives"><div class="repeater-row"><input type="hidden" class="delete-flag" name="relatives[__INDEX__][_delete]" value="0"><div class="form-grid"><label>Relationship<input name="relatives[__INDEX__][relationship]"></label><label>Name<input name="relatives[__INDEX__][name]"></label><label>Phone<input name="relatives[__INDEX__][phone]"></label><label>Date of birth<input type="date" name="relatives[__INDEX__][date_of_birth]"></label><label class="check-field"><input type="hidden" name="relatives[__INDEX__][is_dependent]" value="0"><input type="checkbox" name="relatives[__INDEX__][is_dependent]" value="1"> Dependant</label></div><button type="button" class="remove-row">Remove</button></div></template>
    <dialog class="employee-picker-dialog" data-relative-dialog aria-labelledby="relative-dialog-title">
        <div class="employee-picker-content">
            <h2 id="relative-dialog-title">Add Relative</h2>
            <div class="form-grid">
                <label>Relationship<input type="text" data-relative-field="relationship" autocomplete="off"></label>
                <label>Name<input type="text" data-relative-field="name" autocomplete="off"></label>
                <label>Phone<input type="tel" data-relative-field="phone" autocomplete="off"></label>
                <label>Date of birth<input type="date" data-relative-field="date_of_birth"></label>
                <label class="check-field"><input type="checkbox" data-relative-field="is_dependent"> Dependant</label>
            </div>
            <div class="dialog-actions"><button type="button" class="btn" data-close-relative-dialog>Cancel</button><button type="button" class="btn btn-primary" data-save-relative>Add relative</button></div>
        </div>
    </dialog>
</section>
