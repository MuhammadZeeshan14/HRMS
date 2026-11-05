<div class="panel-bottom-actions">
    <button class="btn primary" type="submit"><x-icon name="save" />{{ $employee->exists ? 'Save changes' : 'Create Employee' }}</button>
    <button class="btn dark" type="button" data-next-section="{{ $nextSection }}">{{ $nextLabel }} <x-icon name="chevron" /></button>
</div>
