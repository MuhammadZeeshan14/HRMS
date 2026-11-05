(() => {
    const form = document.querySelector('[data-import-mapping]');
    if (!form) return;
    const layout = form.querySelector('[data-import-layout]');
    function sync() {
        form.querySelectorAll('[data-mapping-layout]').forEach(label => {
            label.hidden = label.dataset.mappingLayout !== layout.value;
            label.querySelectorAll('select').forEach(input => { input.disabled = label.hidden; });
        });
    }
    layout.addEventListener('change', sync);
    sync();
})();
