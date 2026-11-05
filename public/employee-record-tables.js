// Keep the existing submitted fields; present persisted related records as tables.
document.querySelectorAll('.employee-form .entry-record-panel [data-repeater]').forEach(repeater => {
  const saved = [...repeater.querySelectorAll(':scope > .repeater-row')].filter(row => row.querySelector('input[name$="[id]"]')?.value);
  if (!saved.length) return;
  const title = repeater.previousElementSibling?.querySelector('h2')?.textContent.trim() || 'Record';
  const wrapper = document.createElement('div');
  wrapper.className = 'employee-record-scroll';
  wrapper.tabIndex = 0;
  wrapper.setAttribute('role', 'region');
  wrapper.setAttribute('aria-label', `${title} saved records`);
  const table = document.createElement('table');
  table.className = 'employee-record-table';
  const caption = table.createCaption();
  caption.textContent = `${title} saved records`;
  caption.className = 'sr-only';
  const head = table.createTHead().insertRow();
  const body = table.createTBody();
  const labels = row => [...row.querySelectorAll('.form-grid > label')];
  const labelName = label => {
    const copy = label.cloneNode(true);
    copy.querySelectorAll('input,select,textarea,small,a').forEach(item => item.remove());
    return copy.textContent.trim();
  };
  [...labels(saved[0]).map(labelName), 'Actions'].forEach(name => {
    const th = document.createElement('th');
    th.scope = 'col'; th.textContent = name; head.append(th);
  });
  wrapper.append(table); repeater.prepend(wrapper);

  saved.forEach(editor => {
    const deleted = editor.querySelector('.delete-flag');
    if (deleted?.value === '1') {
      editor.hidden = true;
      editor.querySelectorAll('input,select,textarea').forEach(control => { if (control !== deleted && !control.name.endsWith('[id]')) control.disabled = true; });
      return;
    }
    const tr = body.insertRow();
    const fields = labels(editor);
    const cells = fields.map(() => tr.insertCell());
    const actions = tr.insertCell(); actions.className = 'employee-record-actions';
    const dialog = document.createElement('dialog');
    dialog.className = 'employee-record-dialog';
    dialog.setAttribute('aria-label', `Edit ${title.toLowerCase()} record`);
    const header = document.createElement('div'); header.className = 'employee-record-dialog-head';
    const heading = document.createElement('h2'); heading.textContent = `Edit ${title.toLowerCase()} record`;
    header.append(heading);
    const footer = document.createElement('div'); footer.className = 'employee-record-dialog-foot';
    const hint = document.createElement('small'); hint.textContent = 'Use Save changes to save your updates to the employee.';
    const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'btn'; cancel.textContent = 'Cancel';
    const done = document.createElement('button'); done.type = 'button'; done.className = 'btn primary'; done.textContent = 'Done';
    footer.append(hint, cancel, done);
    editor.hidden = true;
    dialog.append(header, editor, footer); repeater.closest('.entry-record-panel').append(dialog);
    const controls = [...editor.querySelectorAll('input,select,textarea')];
    let snapshot;
    const refresh = () => fields.forEach((label, index) => {
      const control = label.querySelector('input:not([type=hidden]),select,textarea');
      let value = control?.value || '';
      if (control?.tagName === 'SELECT') value = control.value ? control.selectedOptions[0]?.textContent : '';
      if (control?.type === 'checkbox') value = control.checked ? 'Yes' : 'No';
      if (control?.type === 'file') value = control.files[0]?.name || (label.querySelector('small') ? 'Attached file' : '');
      cells[index].textContent = value || '—';
      cells[index].title = value;
    });
    const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'btn'; edit.textContent = 'Edit';
    const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn danger'; remove.textContent = 'Remove';
    const actionGroup = document.createElement('div'); actionGroup.append(edit, remove); actions.append(actionGroup);
    edit.addEventListener('click', () => {
      snapshot = controls.map(control => ({value: control.value, checked: control.checked, files: control.files}));
      editor.hidden = false; dialog.showModal();
    });
    const dismiss = () => {
      controls.forEach((control, index) => {
        if (control.type === 'file') { if (snapshot[index].files) control.files = snapshot[index].files; return; }
        control.value = snapshot[index].value;
        if (control.type === 'checkbox') control.checked = snapshot[index].checked;
      });
      editor.hidden = true; dialog.close(); edit.focus();
    };
    cancel.addEventListener('click', dismiss);
    dialog.addEventListener('cancel', event => { event.preventDefault(); dismiss(); });
    dialog.addEventListener('keydown', event => {
      if (event.key === 'Enter' && event.target.matches('input,select')) { event.preventDefault(); done.click(); }
    });
    dialog.addEventListener('invalid', () => {
      if (!dialog.open) {
        snapshot = controls.map(control => ({value: control.value, checked: control.checked, files: control.files}));
        editor.hidden = false; dialog.showModal();
      }
    }, true);
    done.addEventListener('click', () => {
      if (!controls.every(control => control.reportValidity())) return;
      refresh(); editor.hidden = true; dialog.close(); edit.focus();
    });
    remove.addEventListener('click', () => {
      deleted.value = '1'; tr.remove(); editor.hidden = true;
      controls.forEach(control => { if (control !== deleted && !control.name.endsWith('[id]')) control.disabled = true; });
      wrapper.hidden = !body.rows.length;
    });
    refresh();
  });
  wrapper.hidden = !body.rows.length;
});
