(() => {
    const root = document.querySelector('[data-import-review]');
    const form = root?.querySelector('[data-import-review-form]');
    if (!form) return;
    const rows = [...form.querySelectorAll('[data-import-row]')];
    const header = form.querySelector('[data-import-select-page]');
    const validate = form.querySelector('[data-validate-import]');
    const save = root.querySelector('[data-save-import]');
    const initialSaveDisabled = save.disabled;
    let submitting = false;
    const dirty = () => rows.some(row => row.dataset.dirty === '1');
    // Only the compact JSON change set is posted, avoiding PHP's input-variable limit.
    form.querySelectorAll('[data-import-row] [name]').forEach(input => input.removeAttribute('name'));
    function sync() {
        const included = rows.filter(row => row.querySelector('[data-import-include]').checked).length;
        header.checked = rows.length > 0 && included === rows.length;
        header.indeterminate = included > 0 && included < rows.length;
        header.disabled = rows.length === 0;
        validate.disabled = !dirty();
        save.disabled = dirty() || initialSaveDisabled;
        root.classList.toggle('is-dirty', dirty());
        root.querySelector('[data-import-dirty]').textContent = dirty() ? 'Unsaved edits — validate changes before paging or saving attendance.' : 'Preview is validated.';
    }
    function mark(row) {
        row.dataset.dirty = '1';
        row.classList.add('import-dirty-row');
        row.querySelector('[data-import-validation]').textContent = 'Needs validation';
        row.querySelector('[data-import-day-status]').textContent = 'Validate to recalculate';
        sync();
        root.dispatchEvent(new Event('attendance-preview-edited'));
    }
    function include(row, enabled) {
        row.querySelector('[data-import-include]').checked = enabled;
        row.classList.toggle('import-excluded', !enabled);
        row.querySelectorAll('input:not([data-import-include]),select,[data-clear-punch]').forEach(input => { input.disabled = !enabled; });
    }
    function stamp(punch) {
        const date = punch.querySelector('[data-punch-date]').value;
        const hour = punch.querySelector('[data-clock-hour]').value;
        const minute = punch.querySelector('[data-clock-minute]').value;
        const period = punch.querySelector('[data-clock-period]').value;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !/^(0?[1-9]|1[0-2])$/.test(hour) || !/^[0-5]?\d$/.test(minute)) return null;
        const h = Number(hour) % 12 + (period === 'PM' ? 12 : 0);
        const original = /^(?:\d{4}-\d{2}-\d{2} )?(\d{2}):(\d{2}):(\d{2})$/.exec(punch.dataset.original);
        const seconds = original && Number(original[1]) === h && Number(original[2]) === Number(minute) ? Number(original[3]) : 0;
        const [year, month, day] = date.split('-').map(Number);
        return Date.UTC(year, month - 1, day, h, Number(minute), seconds);
    }
    function duration(row) {
        const a = stamp(row.querySelector('[data-import-punch=time_in]'));
        const b = stamp(row.querySelector('[data-import-punch=time_out]'));
        let display = '—';
        if (a !== null && b !== null && b > a) {
            const seconds = Math.round((b - a) / 1000);
            display = `${Math.floor(seconds / 3600)}:${String(Math.floor(seconds % 3600 / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
        }
        row.querySelector('[data-import-duration]').textContent = display;
    }
    function punchValue(punch) {
        if (Object.hasOwn(punch.dataset, 'pendingRaw')) return punch.dataset.pendingRaw;
        if (punch.dataset.edited !== '1') return punch.dataset.original || '';
        const hour = punch.querySelector('[data-clock-hour]').value;
        const minute = punch.querySelector('[data-clock-minute]').value;
        if (!hour && !minute) return '';
        const date = punch.querySelector('[data-punch-date]').value;
        if (!/^(0?[1-9]|1[0-2])$/.test(hour) || !/^[0-5]?\d$/.test(minute)) return 'Invalid clock';
        const h = Number(hour) % 12 + (punch.querySelector('[data-clock-period]').value === 'PM' ? 12 : 0);
        const original = /(?:^|[ T])(\d{2}):(\d{2}):(\d{2})$/.exec(punch.dataset.original || '');
        const seconds = original && Number(original[1]) === h && Number(original[2]) === Number(minute) ? original[3] : '00';
        return `${date} ${String(h).padStart(2, '0')}:${String(minute).padStart(2, '0')}:${seconds}`;
    }
    rows.forEach(row => {
        include(row, row.querySelector('[data-import-include]').checked);
        row.addEventListener('input', event => {
            const punch = event.target.closest('[data-import-punch]');
            if (punch) { punch.dataset.edited = '1'; delete punch.dataset.pendingRaw; duration(row); }
            if (event.target.matches('[data-import-field]')) {
                event.target.dataset.edited = '1';
                delete event.target.dataset.gridRaw;
                row.querySelector('[data-import-identity]').textContent = 'Validate to confirm employee';
                row.querySelector('[data-import-shift]').textContent = 'Validate to confirm shift';
            }
            mark(row);
        });
        row.addEventListener('change', event => {
            if (event.target.matches('[data-import-field]')) { event.target.dataset.edited = '1'; delete event.target.dataset.gridRaw; }
            if (event.target.matches('[data-import-include]')) include(row, event.target.checked);
            const punch = event.target.closest('[data-import-punch]');
            if (punch) { punch.dataset.edited = '1'; delete punch.dataset.pendingRaw; duration(row); }
            mark(row);
        });
        row.querySelectorAll('[data-clear-punch]').forEach(button => button.addEventListener('click', () => {
            const punch = button.closest('[data-import-punch]');
            punch.querySelector('[data-clock-hour]').value = '';
            punch.querySelector('[data-clock-minute]').value = '';
            punch.querySelector('[data-clock-hour]').dispatchEvent(new Event('input', { bubbles: true }));
        }));
        row.querySelector('[data-refresh-import-row]').addEventListener('click', () => {
            row.dataset.refresh = '1'; mark(row);
            form.requestSubmit();
        });
    });
    header.addEventListener('change', () => { const enabled = header.checked; rows.forEach(row => { include(row, enabled); mark(row); }); sync(); });
    form.querySelector('[data-include-page]').addEventListener('click', () => { rows.forEach(row => { include(row, true); mark(row); }); sync(); });
    form.querySelector('[data-exclude-page]').addEventListener('click', () => { rows.forEach(row => { include(row, false); mark(row); }); sync(); });
    form.addEventListener('submit', event => {
        const edits = rows.filter(row => row.dataset.dirty === '1').map(row => {
            const edit = { row: Number(row.dataset.importRow), excluded: !row.querySelector('[data-import-include]').checked,
                refresh_current: row.dataset.refresh === '1' };
            row.querySelectorAll('[data-import-field][data-edited="1"]').forEach(input => { edit[input.dataset.importField] = input.dataset.gridRaw ?? input.value; });
            row.querySelectorAll('[data-import-punch][data-edited="1"]').forEach(punch => {
                const name = punch.dataset.importPunch;
                edit[name] = punchValue(punch);
            });
            return edit;
        });
        if (!edits.length && !event.submitter?.matches('[data-auto-fix-import]')) { event.preventDefault(); return; }
        form.elements.changes.value = JSON.stringify(edits);
        submitting = true;
    });
    root.querySelector('.import-filter').addEventListener('submit', event => { if (dirty()) { event.preventDefault(); validate.focus(); } });
    root.querySelectorAll('.pager a').forEach(link => link.addEventListener('click', event => { if (dirty()) { event.preventDefault(); validate.focus(); } }));
    root.querySelector('[data-import-commit]').addEventListener('submit', event => { if (dirty()) event.preventDefault(); else submitting = true; });
    window.addEventListener('beforeunload', event => { if (dirty() && !submitting) { event.preventDefault(); event.returnValue = ''; } });
    root.attendanceSheet = { rows, mark, include, duration, punchValue };
    sync();
})();
