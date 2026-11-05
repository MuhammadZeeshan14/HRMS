const employeeForm = document.querySelector('.employee-form');
if (employeeForm) {
 document.addEventListener('keydown', event => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); employeeForm.requestSubmit(); } });
 employeeForm.addEventListener('invalid', event => { const panel = event.target.closest('.tab-panel'); if (panel?.hidden) document.querySelector(`[data-tab="${panel.id}"]`)?.click(); }, true);
 employeeForm.querySelectorAll('[data-next-section]').forEach(button => button.addEventListener('click', () => {
   const target = employeeForm.querySelector(`[data-tab="${button.dataset.nextSection}"]`);
   target?.click();
   employeeForm.querySelector('.form-tabs')?.scrollIntoView({behavior:'smooth',block:'start'});
 }));
 const photo = document.getElementById('photo'); let previewUrl;
 const designationSearch = employeeForm.querySelector('[data-designation-search]');
 const designationId = employeeForm.querySelector('#designation_id');
 if (designationSearch && designationId) {
   const options = [...employeeForm.querySelectorAll('#designation-options option')];
   const resolveDesignation = () => {
     const match = options.find(option => option.value === designationSearch.value.trim());
     designationId.value = match?.dataset.id || '';
     designationSearch.setCustomValidity(designationSearch.value.trim() && !match ? 'Choose a designation from the list.' : '');
   };
   designationSearch.addEventListener('input', resolveDesignation);
   designationSearch.addEventListener('change', resolveDesignation);
   designationSearch.addEventListener('blur', resolveDesignation);
   resolveDesignation();
 }
 const languageDialog = employeeForm.querySelector('[data-language-dialog]');
 if (languageDialog) {
   const summary = employeeForm.querySelector('[data-language-summary]');
   const openButton = employeeForm.querySelector('[data-open-language-dialog]');
   const updateLanguages = () => {
     const selected = [...languageDialog.querySelectorAll('input[type="checkbox"]:checked')].map(input => input.nextElementSibling.textContent.trim());
     summary.textContent = selected.length ? selected.join(', ') : 'No languages selected';
     summary.title = summary.textContent;
   };
   languageDialog.addEventListener('change', updateLanguages);
   updateLanguages();
   openButton.addEventListener('click', () => languageDialog.showModal());
   languageDialog.querySelector('[data-close-language-dialog]').addEventListener('click', () => languageDialog.close());
   languageDialog.querySelector('[data-language-search]').addEventListener('input', event => {
     const query = event.target.value.trim().toLocaleLowerCase();
     const options = [...languageDialog.querySelectorAll('.language-option')];
     options.forEach(option => { option.hidden = !option.textContent.toLocaleLowerCase().includes(query); });
     languageDialog.querySelector('[data-language-no-results]').hidden = options.some(option => !option.hidden);
   });
   languageDialog.addEventListener('close', () => openButton.focus());
 }
 const relativeDialog = employeeForm.querySelector('[data-relative-dialog]');
 if (relativeDialog) {
   const openButton = employeeForm.querySelector('[data-open-relative-dialog]');
   const relativeRepeater = employeeForm.querySelector('[data-repeater="relatives"]');
   const relativeTemplate = employeeForm.querySelector('template[data-template="relatives"]');
   const clearRelativeDialog = () => {
     relativeDialog.querySelectorAll('[data-relative-field]').forEach(field => {
       if (field.type === 'checkbox') field.checked = false;
       else field.value = '';
     });
   };
   openButton.addEventListener('click', () => relativeDialog.showModal());
   relativeDialog.querySelector('[data-close-relative-dialog]').addEventListener('click', () => relativeDialog.close());
   relativeDialog.querySelector('[data-save-relative]').addEventListener('click', () => {
     const index = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
     const fragment = relativeTemplate.content.cloneNode(true);
     fragment.querySelectorAll('[name]').forEach(field => { field.name = field.name.replaceAll('__INDEX__', index); });
     fragment.querySelector('[name$="[relationship]"]').value = relativeDialog.querySelector('[data-relative-field="relationship"]').value;
     fragment.querySelector('[name$="[name]"]').value = relativeDialog.querySelector('[data-relative-field="name"]').value;
     fragment.querySelector('[name$="[phone]"]').value = relativeDialog.querySelector('[data-relative-field="phone"]').value;
     fragment.querySelector('[name$="[date_of_birth]"]').value = relativeDialog.querySelector('[data-relative-field="date_of_birth"]').value;
     fragment.querySelector('[name$="[is_dependent]"][type="checkbox"]').checked = relativeDialog.querySelector('[data-relative-field="is_dependent"]').checked;
     relativeRepeater.append(fragment);
     relativeDialog.close();
     clearRelativeDialog();
   });
   relativeDialog.addEventListener('close', () => { clearRelativeDialog(); openButton.focus(); });
 }
 photo?.addEventListener('change', () => { if (!photo.files[0]) return; if (previewUrl) URL.revokeObjectURL(previewUrl); previewUrl = URL.createObjectURL(photo.files[0]); const image = document.createElement('img'); image.src = previewUrl; image.alt = 'Employee photo preview'; document.querySelector('.employee-photo-preview').replaceChildren(image); });
}
