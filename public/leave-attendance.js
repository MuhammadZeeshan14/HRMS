document.querySelectorAll('[data-la-leave]').forEach(form => {
    const start = form.elements.starts_on, end = form.elements.ends_on, output = form.querySelector('[data-la-days]');
    let request;
    const update = async () => {
        request?.abort();
        if (!start.value || !end.value) { output.textContent = 'Select dates to calculate'; return; }
        request = new AbortController();
        const url = new URL(form.dataset.previewUrl, window.location.origin);
        url.search = new URLSearchParams({employee_id: form.elements.employee_id.value, starts_on: start.value, ends_on: end.value});
        try {
            const response = await fetch(url, {signal: request.signal, headers: {'Accept': 'application/json'}});
            const data = await response.json();
            output.textContent = response.ok ? `${data.days} working days` : (data.message || 'Check the selected dates');
        } catch (error) { if (error.name !== 'AbortError') output.textContent = 'Preview unavailable; days will be calculated when submitted'; }
    };
    start.addEventListener('change', update); end.addEventListener('change', update); update();
});
