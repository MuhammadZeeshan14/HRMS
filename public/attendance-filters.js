(() => {
    const controls = [...document.querySelectorAll('[data-register-multiselect]')];
    for (const control of controls) {
        const options = [...control.querySelectorAll('[data-filter-option]')];
        const summary = control.querySelector('[data-filter-summary]');
        const search = control.querySelector('[data-filter-search]');
        control.querySelector('[data-filter-tools]').hidden = false;
        function update() {
            const selected = options.filter(option => option.querySelector('input').checked).map(option => option.querySelector('span').textContent.trim());
            summary.textContent = selected.length === 1 ? selected[0] : selected.length ? `${selected.length} selected` : control.dataset.allLabel;
            summary.title = selected.join(', ');
        }
        control.addEventListener('change', update);
        control.addEventListener('toggle', () => {
            if (!control.open) return;
            controls.forEach(other => { if (other !== control) other.open = false; });
        });
        search.addEventListener('input', () => {
            const term = search.value.trim().toLocaleLowerCase();
            options.forEach(option => { option.hidden = !option.textContent.toLocaleLowerCase().includes(term); });
            control.querySelector('[data-filter-no-results]').hidden = options.some(option => !option.hidden);
        });
        control.querySelector('[data-filter-select-all]').addEventListener('click', () => {
            options.filter(option => !option.hidden).forEach(option => { option.querySelector('input').checked = true; }); update();
        });
        control.querySelector('[data-filter-clear]').addEventListener('click', () => {
            options.forEach(option => { option.querySelector('input').checked = false; }); update();
        });
        control.querySelector('[data-filter-done]').addEventListener('click', () => { control.open = false; control.querySelector('summary').focus(); });
        control.addEventListener('keydown', event => {
            if (event.key === 'Escape') { control.open = false; control.querySelector('summary').focus(); event.preventDefault(); }
        });
    }
    document.addEventListener('click', event => { controls.forEach(control => { if (!control.contains(event.target)) control.open = false; }); });
})();
