const directoryFilters = document.querySelector('.directory-filters');
directoryFilters?.querySelectorAll('select').forEach(select => select.addEventListener('change', () => directoryFilters.requestSubmit()));
const selectAll = document.querySelector('[data-select-all]');
const rows = Array.from(document.querySelectorAll('[data-select-row]'));
selectAll?.addEventListener('change', () => rows.forEach(row => {row.checked = selectAll.checked; row.closest('tr').classList.toggle('selected',row.checked);}));
rows.forEach(row => row.addEventListener('change', () => { row.closest('tr').classList.toggle('selected',row.checked); selectAll.checked = rows.every(row => row.checked); selectAll.indeterminate = rows.some(row => row.checked) && !selectAll.checked; }));
document.querySelector('[form="directory-filters"]')?.addEventListener('change', () => directoryFilters.requestSubmit());
document.querySelectorAll('[data-directory-view]').forEach(button => button.addEventListener('click', () => {
 document.querySelector('.directory-table').classList.toggle('grid-view',button.dataset.directoryView === 'grid');
 document.querySelectorAll('[data-directory-view]').forEach(item => {item.classList.toggle('active',item===button); item.setAttribute('aria-pressed',item===button ? 'true' : 'false');});
}));
