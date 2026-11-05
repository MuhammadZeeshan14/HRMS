(() => {
    const form = document.querySelector('[data-policy-filter]');
    if (!form) return;
    const employee = form.querySelector('[data-policy-employee]');
    const search = form.querySelector('[data-policy-employee-search]');
    const matches = new Map([...form.querySelectorAll('#policy-employee-results option')].map(option => [option.value, option.dataset.employeeId]));
    function matchEmployee() {
        if (!search || search.disabled) return false;
        const id = matches.get(search.value);
        employee.value = id || '';
        search.setCustomValidity(id ? '' : 'Select an employee from the matching search results.');
        return Boolean(id);
    }
    search?.addEventListener('input', matchEmployee);
    search?.addEventListener('change', () => {
        if (matchEmployee() && form.reportValidity()) form.requestSubmit();
    });
    form.querySelector('[data-policy-status]').addEventListener('change', () => {
        if (employee) employee.value = '';
        const disabled = search?.disabled;
        if (search) search.disabled = true;
        if (form.reportValidity()) form.requestSubmit();
        else if (search) search.disabled = disabled;
    });
})();
