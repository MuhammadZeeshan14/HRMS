(() => {
    const form = document.querySelector('[data-attendance-editor]');
    if (!form) return;
    const arrival = form.elements.time_in;
    const departure = form.elements.time_out;
    const duration = form.querySelector('[data-attendance-duration]');
    const saveState = form.querySelector('[data-attendance-save-state]');
    const reasonType = form.elements.reason_type;
    const updateRemarks = () => {
        const required = reasonType.value === 'other';
        form.elements.reason.required = required;
        const marker = form.querySelector('[data-remarks-required]');
        if (marker) marker.hidden = !required;
    };
    reasonType.addEventListener('change', updateRemarks);
    updateRemarks();
    form.querySelectorAll('[data-hrla-datetime]').forEach(group => {
        const canonical = group.querySelector(':scope > input[type=hidden]');
        const date = group.querySelector('input[type=date]');
        const clock = group.querySelector('[data-clock-value]');
        const original = /[ T](\d{2}:\d{2}):(\d{2})$/.exec(canonical.value);
        const sync = () => {
            const match = /^(0?[1-9]|1[0-2]):([0-5]\d)\s*(AM|PM)$/i.exec(clock.value.trim());
            if (!date.value || !match) { canonical.value = ''; return; }
            const hours = (Number(match[1]) % 12) + (match[3].toUpperCase() === 'PM' ? 12 : 0);
            const minutes = String(hours).padStart(2,'0') + ':' + match[2];
            canonical.value = date.value + 'T' + minutes + ':' + (original && original[1] === minutes ? original[2] : '00');
        };
        group.addEventListener('input',sync);
        group.addEventListener('hrla:clock-change',sync);
    });
    // Datetime inputs contain business-local wall times; duration must not depend on the viewer's timezone.
    const wallTime = value => {
        const parts = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/.exec(value);
        return parts ? Date.UTC(+parts[1], +parts[2] - 1, +parts[3], +parts[4], +parts[5], +(parts[6] || 0)) : null;
    };
    const updateDuration = () => {
        const start = wallTime(arrival.value), end = wallTime(departure.value);
        if (start === null || end === null) { duration.textContent = 'Enter both punches to calculate'; return; }
        if (end <= start) { duration.textContent = 'Checkout must be after check-in'; return; }
        const seconds = Math.round((end - start) / 1000);
        duration.textContent = `${Math.floor(seconds / 3600)} hrs ${String(Math.floor(seconds % 3600 / 60)).padStart(2, '0')} mins ${String(seconds % 60).padStart(2, '0')} secs`;
    };
    form.addEventListener('input', () => {
        saveState.replaceChildren(document.createElement('i'), document.createTextNode('Unsaved attendance changes'));
        updateDuration();
    });
    updateDuration();
})();
