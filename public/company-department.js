(() => {
    const chart = document.querySelector('.department-chart');
    if (!chart) return;
    const viewport = chart.querySelector('.department-viewport');
    const canvas = chart.querySelector('.department-canvas');
    const sizer = chart.querySelector('.department-sizer');
    const output = chart.querySelector('[data-chart-zoom]');
    let zoom = 1;
    const size = () => {
        sizer.style.width = `${Math.ceil(canvas.offsetWidth * zoom)}px`;
        sizer.style.height = `${Math.ceil(canvas.offsetHeight * zoom)}px`;
        canvas.style.transform = `scale(${zoom})`;
        output.textContent = `${Math.round(zoom * 100)}%`;
    };
    const setZoom = value => {
        const center = (viewport.scrollLeft + viewport.clientWidth / 2) / zoom;
        zoom = Math.max(0.1, Math.min(2, value));
        size();
        viewport.scrollLeft = center * zoom - viewport.clientWidth / 2;
    };
    const toggle = (button, expanded) => {
        document.getElementById(button.getAttribute('aria-controls')).hidden = !expanded;
        button.setAttribute('aria-expanded', String(expanded));
        button.textContent = expanded ? 'Hide branch' : 'Show branch';
    };
    chart.querySelectorAll('[data-branch-toggle]').forEach(button => button.addEventListener('click', () => {
        toggle(button, button.getAttribute('aria-expanded') !== 'true');
        size();
    }));
    const centerRoot = () => { viewport.scrollLeft = (sizer.offsetWidth - viewport.clientWidth) / 2; viewport.scrollTop = 0; };
    const setAll = expanded => { chart.querySelectorAll('[data-branch-toggle]').forEach(button => toggle(button, expanded)); size(); centerRoot(); };
    chart.querySelector('[data-department-expand]').addEventListener('click', () => setAll(true));
    chart.querySelector('[data-department-collapse]').addEventListener('click', () => setAll(false));
    chart.querySelector('[data-chart-in]').addEventListener('click', () => setZoom(zoom + 0.1));
    chart.querySelector('[data-chart-out]').addEventListener('click', () => setZoom(zoom - 0.1));
    chart.querySelector('[data-chart-reset]').addEventListener('click', () => { setZoom(1); centerRoot(); });
    chart.querySelector('[data-chart-fit]').addEventListener('click', () => { setZoom(Math.min(1, viewport.clientWidth / canvas.offsetWidth)); viewport.scrollLeft = 0; viewport.scrollTop = 0; });
    new ResizeObserver(size).observe(canvas);
    size();
    viewport.scrollLeft = (sizer.offsetWidth - viewport.clientWidth) / 2;
})();

