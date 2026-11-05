(() => {
    const table = document.querySelector('[data-shift-table]');
    if (!table) return;
    const boxes = [...table.querySelectorAll('[data-select-employee]')];
    const page = table.querySelector('[data-select-page]');
    const form = table.querySelector('[data-shift-form="set"]');
    const dialog = table.querySelector('[data-shift-dialog="set"]');
    const open = table.querySelector('[data-open-shift-dialog="set"]');
    const fields = ['shift_id', 'starts_on', 'ends_on'];
    let saved = {};
    try { saved = JSON.parse(sessionStorage.getItem(table.dataset.storageKey) || '{}') || {}; } catch (_) {}
    if (table.dataset.saved === '1') saved = {};
    const selected = new Set((Array.isArray(saved.selection) ? saved.selection : [])
        .map(entry => String(Array.isArray(entry) ? entry[0] : entry)).filter(id => /^\d+$/.test(id)));
    if (table.dataset.reopen) {
        try {
            const initial = JSON.parse(table.dataset.initial);
            if (Array.isArray(initial)) { selected.clear(); initial.forEach(id => { if (/^\d+$/.test(String(id))) selected.add(String(id)); }); }
        } catch (_) {}
    } else {
        const draft = saved.drafts?.set || saved.drafts?.assign || {};
        fields.forEach(name => { if (Object.hasOwn(draft, name)) form.elements[name].value = draft[name]; });
    }
    function update() {
        boxes.forEach(box => { box.checked = selected.has(box.value); });
        const checked = boxes.filter(box => box.checked).length;
        page.checked = boxes.length > 0 && checked === boxes.length;
        page.indeterminate = checked > 0 && checked < boxes.length;
        page.disabled = boxes.length === 0;
        const outside = selected.size - checked;
        const count = `${selected.size} employee${selected.size === 1 ? '' : 's'} selected`;
        table.querySelector('[data-selection-count]').textContent = count + (outside ? ` (${outside} outside this page)` : '');
        form.querySelector('[data-modal-count]').textContent = count;
        form.elements.employee_ids_payload.value = JSON.stringify([...selected]);
        open.disabled = !selected.size || form.elements.shift_id.options.length < 2;
        const note = table.querySelector('[data-selection-note]');
        if (note) note.textContent = 'The latest saved assignment overrides older assignments for its effective dates. Selections stay selected across pages and filters for the same assignment date.';
        const draft = Object.fromEntries(fields.map(name => [name, form.elements[name].value]));
        try { sessionStorage.setItem(table.dataset.storageKey, JSON.stringify({ selection: [...selected], drafts: { set: draft } })); } catch (_) {}
    }
    boxes.forEach(box => box.addEventListener('change', () => {
        if (box.checked) selected.add(box.value); else selected.delete(box.value);
        update();
    }));
    page.addEventListener('change', () => {
        const include = page.checked;
        boxes.forEach(box => { if (include) selected.add(box.value); else selected.delete(box.value); }); update();
    });
    table.querySelector('[data-clear-selection]').addEventListener('click', () => { selected.clear(); update(); });
    fields.forEach(name => ['input', 'change'].forEach(event => form.elements[name].addEventListener(event, update)));
    form.addEventListener('submit', event => { update(); if (!selected.size) event.preventDefault(); });
    open.addEventListener('click', () => { update(); if (!open.disabled) dialog.showModal(); });
    update();
    if (table.dataset.reopen) dialog.showModal();
})();
