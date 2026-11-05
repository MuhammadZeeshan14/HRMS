(() => {
    const root = document.querySelector('[data-import-review]');
    const api = root?.attendanceSheet;
    if (!api) return;
    const { rows, mark, include, duration, punchValue } = api;
    const fields = ['employee_no', 'shift_date', 'time_in', 'time_out'];
    const toolbar = root.querySelector('[data-sheet-toolbar]');
    const message = text => { toolbar.querySelector('[data-sheet-message]').textContent = text; };
    const sources = new Map(JSON.parse(root.querySelector('[data-sheet-source-rows]').textContent).map(row => [Number(row.row), row.cells]));
    let anchor = null, active = null, busy = false;
    let editingCell = null;
    const undo = [], redo = [];
    function value(row, field) {
        const input = row.querySelector(`[data-import-field="${field}"]`);
        return input ? (input.dataset.gridRaw ?? (input.dataset.edited === '1' ? input.value : input.dataset.originalValue ?? input.value)) : punchValue(row.querySelector(`[data-import-punch="${field}"]`));
    }
    const snapshot = () => rows.map(row => ({ values: fields.map(field => value(row, field)), included: row.querySelector('[data-import-include]').checked }));
    let previous = snapshot();
    function history() {
        if (busy) return;
        const next = snapshot();
        if (JSON.stringify(previous) === JSON.stringify(next)) return;
        undo.push(previous); if (undo.length > 30) undo.shift(); redo.length = 0; previous = next; syncHistory();
    }
    function syncHistory() {
        toolbar.querySelector('[data-sheet-undo]').disabled = !undo.length;
        toolbar.querySelector('[data-sheet-redo]').disabled = !redo.length;
    }
    root.addEventListener('attendance-preview-edited', history);
    function clock(text) {
        const match = /^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?\s*(AM|PM)?$/i.exec(text.trim());
        if (!match) return null;
        let hour = Number(match[1]);
        if (match[4]) { if (hour < 1 || hour > 12) return null; hour = hour % 12 + (match[4].toUpperCase() === 'PM' ? 12 : 0); }
        if (hour > 23) return null;
        return `${String(hour).padStart(2, '0')}:${match[2]}:${match[3] || '00'}`;
    }
    function date(text) {
        const part = text.trim().split(/[ T]/)[0];
        let match = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/.exec(part);
        let y, m, d;
        if (match) [, y, m, d] = match;
        else {
            match = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/.exec(part);
            if (!match) return null;
            const order = root.querySelector('[data-fix-date-order]')?.value || root.closest('.attendance-import').querySelector('[name=date_order]')?.value || 'auto';
            const a = Number(match[1]), b = Number(match[2]);
            if (order === 'dmy' || order === 'auto' && (a > 12 || a === b)) { d = a; m = b; }
            else if (order === 'mdy' || order === 'auto' && b > 12) { m = a; d = b; }
            else return null;
            y = match[3];
        }
        const test = new Date(Date.UTC(Number(y), Number(m) - 1, Number(d)));
        if (test.getUTCFullYear() !== Number(y) || test.getUTCMonth() + 1 !== Number(m) || test.getUTCDate() !== Number(d)) return null;
        return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    }
    function convert(row, field, text) {
        text = String(text ?? '').trim();
        if (text.length > 255) throw new Error('A cell exceeds 255 characters.');
        if (!text || field === 'employee_no') return text;
        if (field === 'shift_date') {
            const parsed = date(text);
            return parsed || text;
        }
        const full = /^(.+?)[ T]+(\d{1,2}:[0-5]\d(?::[0-5]\d)?\s*(?:AM|PM)?)$/i.exec(text);
        const parsedClock = clock(full ? full[2] : text);
        const parsedDate = full ? date(full[1]) : row.querySelector(`[data-import-punch="${field}"] [data-punch-date]`).value;
        if (!parsedClock || !parsedDate) return text;
        return `${parsedDate} ${parsedClock}`;
    }
    function set(row, field, text) {
        const input = row.querySelector(`[data-import-field="${field}"]`);
        if (input) {
            input.value = text; input.dataset.edited = '1';
            if (input.value !== text) input.dataset.gridRaw = text; else delete input.dataset.gridRaw;
            if (field === 'employee_no') row.querySelector('[data-import-identity]').textContent = 'Validate to resolve employee ID';
            const warning = row.querySelector('[data-sheet-date-warning]');
            if (field === 'shift_date') { warning.textContent = input.dataset.gridRaw || ''; row.querySelector('[data-import-shift]').textContent = 'Validate to resolve shift'; }
        } else {
            const punch = row.querySelector(`[data-import-punch="${field}"]`);
            const parsed = /^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(text);
            const onlyClock = !parsed ? clock(text) : null;
            const pieces = onlyClock?.split(':');
            punch.querySelector('[data-punch-date]').value = parsed ? parsed[1] : '';
            const hour = parsed ? Number(parsed[2]) : pieces ? Number(pieces[0]) : null;
            punch.querySelector('[data-clock-hour]').value = hour !== null ? String(hour % 12 || 12).padStart(2, '0') : '';
            punch.querySelector('[data-clock-minute]').value = parsed ? parsed[3] : pieces ? pieces[1] : '';
            punch.querySelector('[data-clock-period]').value = hour !== null && hour >= 12 ? 'PM' : 'AM';
            punch.dataset.original = text;
            punch.dataset.edited = '1';
            if (parsed || !text) delete punch.dataset.pendingRaw; else punch.dataset.pendingRaw = text;
            punch.querySelectorAll('.import-error').forEach(note => { note.hidden = true; });
            if (!parsed && text) {
                let note = punch.querySelector('[data-sheet-punch-warning]');
                if (!note) { note = document.createElement('small'); note.dataset.sheetPunchWarning=''; note.className='import-error'; punch.append(note); }
                note.hidden=false; note.textContent=onlyClock ? 'Select the punch date, then validate.' : `Unparsed: ${text}`;
            }
        }
    }
    function apply(changes, description) {
        busy = true;
        const changed = new Set();
        for (const change of changes) { set(change.row, change.field, change.text); changed.add(change.row); }
        for (const row of changed) { duration(row); mark(row); }
        busy = false; history(); message(description + ' Validate changes before saving.');
    }
    function restore(from, to) {
        if (!from.length) return;
        const target = from.pop(); to.push(snapshot()); busy = true;
        target.forEach((saved, i) => {
            const row = rows[i]; let changed = false;
            fields.forEach((field, column) => { if (value(row, field) !== saved.values[column]) { set(row, field, saved.values[column]); changed = true; } });
            if (row.querySelector('[data-import-include]').checked !== saved.included) { include(row, saved.included); changed = true; }
            if (changed) { duration(row); mark(row); }
        });
        busy = false; previous = snapshot(); syncHistory(); message('Preview restored. Validate changes before saving.');
    }
    toolbar.querySelector('[data-sheet-undo]').addEventListener('click', () => restore(undo, redo));
    toolbar.querySelector('[data-sheet-redo]').addEventListener('click', () => restore(redo, undo));
    const coords = cell => ({ r: rows.indexOf(cell.closest('[data-import-row]')), c: fields.indexOf(cell.dataset.gridCell) });
    function formatted(text) {
        return text.replace(/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):\d{2}$/, (_, day, hour, minute) => `${day} ${Number(hour)%12||12}:${minute} ${Number(hour)>=12?'PM':'AM'}`)
            .replace(/^(\d{2}):(\d{2}):\d{2}$/, (_, hour, minute) => `${Number(hour)%12||12}:${minute} ${Number(hour)>=12?'PM':'AM'}`);
    }
    function renderCells() {
        rows.forEach(row => fields.forEach(field => {
            const cell=row.querySelector(`[data-grid-cell="${field}"]`), text=value(row,field);
            let display=text;
            if(field==='employee_no') display=row.querySelector('[data-import-identity]>strong')?.textContent || text;
            if(field==='time_in' || field==='time_out') {
                const full=/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):\d{2}$/.exec(text);
                const partial=/^(\d{2}):(\d{2}):\d{2}$/.exec(text);
                if(partial) display=`${Number(partial[1])%12||12}:${partial[2]} ${Number(partial[1])>=12?'PM':'AM'}`;
                if(full) {
                    display=`${Number(full[2])%12||12}:${full[3]} ${Number(full[2])>=12?'PM':'AM'}`;
                    const day=value(row,'shift_date');
                    if(/^\d{4}-\d{2}-\d{2}$/.test(day) && full[1]!==day) {
                        const offset=Math.round((Date.parse(full[1])-Date.parse(day))/86400000);
                        display+=` (${offset>0?'+':''}${offset}d)`;
                    }
                }
            }
            const span=cell.querySelector('.sheet-cell-display'); if(span) span.textContent=display;
            cell.title=field==='employee_no' ? `Employee code: ${text}` : formatted(text);
        }));
        if(active) {
            toolbar.querySelector('[data-sheet-address]').value=`${String.fromCharCode(65+active.c)}${rows[active.r].dataset.sheetRow}`;
            toolbar.querySelector('[data-sheet-formula]').value=formatted(value(rows[active.r],fields[active.c]));
        }
    }
    function inspect() {
        if(!active) return;
        const row=rows[active.r], pane=root.querySelector('[data-sheet-inspector]');
        pane.replaceChildren();
        const heading=document.createElement('strong'); heading.textContent=`Row ${row.dataset.sheetRow} · Original file row ${row.dataset.importRow}`; pane.append(heading);
        for(const selector of ['[data-import-identity]','[data-import-shift]','.import-validation-cell','[data-import-duration]']) {
            const original=row.querySelector(selector); if(!original) continue;
            const box=document.createElement('div'), copy=original.cloneNode(true); box.className='sheet-inspector-part';
            if(selector==='[data-import-duration]') box.append('Worked hours: ');
            const details=copy.querySelector('[data-import-details]'); if(details) details.open=true;
            copy.querySelectorAll('[data-refresh-import-row]').forEach(button=>button.addEventListener('click',()=>row.querySelector('[data-refresh-import-row]').click()));
            box.append(copy); pane.append(box);
        }
    }
    function closeEditor() {
        if(!editingCell) return;
        editingCell.classList.remove('sheet-editing'); editingCell=null;
    }
    function edit(cell) {
        if(!cell || !cell.closest('[data-import-row]').querySelector('[data-import-include]').checked) return;
        closeEditor(); select(cell); editingCell=cell; cell.classList.add('sheet-editing');
        positionEditor();
        const box=cell.querySelector('.sheet-cell-editor');
        const input=box.querySelector('input:not([disabled]):not([type=hidden]),select:not([disabled])');
        input?.focus(); if(input?.type==='text') input.select();
    }
    function positionEditor() {
        if(!editingCell) return;
        const box=editingCell.querySelector('.sheet-cell-editor'), rect=editingCell.getBoundingClientRect();
        box.style.left=`${Math.max(8,Math.min(rect.left,innerWidth-300))}px`;
        box.style.top=`${Math.max(8,Math.min(rect.bottom+2,innerHeight-230))}px`;
    }
    function bounds() { return { r1: Math.min(anchor.r, active.r), r2: Math.max(anchor.r, active.r), c1: Math.min(anchor.c, active.c), c2: Math.max(anchor.c, active.c) }; }
    function select(cell, extend = false) {
        if(editingCell && editingCell!==cell) closeEditor();
        active = coords(cell); if (!extend || !anchor) anchor = active;
        const b = bounds();
        rows.forEach((row, r) => row.querySelectorAll('[data-grid-cell]').forEach((td, c) => td.classList.toggle('sheet-selected', r >= b.r1 && r <= b.r2 && c >= b.c1 && c <= b.c2)));
        root.querySelectorAll('.sheet-column-letters th').forEach((th,index)=>th.classList.toggle('sheet-column-selected',index-1>=b.c1 && index-1<=b.c2));
        toolbar.querySelector('[data-sheet-selection]').textContent = `${String.fromCharCode(65 + b.c1)}${rows[b.r1].dataset.sheetRow}:${String.fromCharCode(65 + b.c2)}${rows[b.r2].dataset.sheetRow} · ${(b.r2-b.r1+1)*(b.c2-b.c1+1)} cells`;
        renderCells(); inspect();
    }
    root.addEventListener('click', event => { const cell = event.target.closest('[data-grid-cell]'); if (cell) { select(cell, event.shiftKey); if (event.shiftKey || event.target === cell) cell.focus(); } });
    root.addEventListener('focusin', event => { const cell = event.target.closest('[data-grid-cell]'); if (cell && !cell.classList.contains('sheet-selected')) select(cell); });
    root.addEventListener('dblclick',event=>{ const cell=event.target.closest('[data-grid-cell]'); if(cell) edit(cell); });
    toolbar.querySelector('[data-sheet-edit]').addEventListener('click',()=>{ if(active) edit(rows[active.r].querySelector(`[data-grid-cell="${fields[active.c]}"]`)); });
    root.addEventListener('attendance-preview-edited',()=>{ renderCells(); inspect(); });
    root.addEventListener('keydown',event=>{ if(event.key==='Escape' && editingCell) { const cell=editingCell; closeEditor(); cell.focus(); event.preventDefault(); } });
    window.addEventListener('scroll',positionEditor,true);
    window.addEventListener('resize',positionEditor);
    root.addEventListener('input',event=>{ if(event.target.matches('[data-import-field=shift_date]')) event.target.closest('[data-import-row]').querySelector('[data-sheet-date-warning]').textContent=''; });
    root.addEventListener('keydown', event => {
        const cell = event.target.closest('[data-grid-cell]');
        if (!cell || event.target !== cell) return;
        const at = coords(cell);
        if (['ArrowUp','ArrowDown','ArrowLeft','ArrowRight'].includes(event.key)) {
            event.preventDefault();
            at.r = Math.max(0, Math.min(rows.length-1, at.r + (event.key==='ArrowUp' ? -1 : event.key==='ArrowDown' ? 1 : 0)));
            at.c = Math.max(0, Math.min(3, at.c + (event.key==='ArrowLeft' ? -1 : event.key==='ArrowRight' ? 1 : 0)));
            const next = rows[at.r].querySelector(`[data-grid-cell="${fields[at.c]}"]`); select(next, event.shiftKey); next.focus();
        } else if (event.key === 'Enter' || event.key === 'F2') { event.preventDefault(); edit(cell); }
        else if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault(); const b = bounds(), changes = [];
            for (let r=b.r1;r<=b.r2;r++) if (rows[r].querySelector('[data-import-include]').checked) for(let c=b.c1;c<=b.c2;c++) changes.push({row:rows[r],field:fields[c],text:''});
            apply(changes, 'Selected cells cleared.');
        }
    });
    function clipboard(event, cut) {
        const cell = event.target.closest('[data-grid-cell]'); if (!cell || !anchor) return;
        const b = bounds(); if (event.target !== cell && b.r1===b.r2 && b.c1===b.c2) return;
        event.preventDefault(); const data = [], changes = [];
        for(let r=b.r1;r<=b.r2;r++) { const line=[]; for(let c=b.c1;c<=b.c2;c++) { line.push(value(rows[r],fields[c])); if(cut && rows[r].querySelector('[data-import-include]').checked) changes.push({row:rows[r],field:fields[c],text:''}); } data.push(line); }
        const display = text => text.replace(/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):\d{2}$/, (_, day, hour, minute) => `${day} ${Number(hour)%12||12}:${minute} ${Number(hour)>=12?'PM':'AM'}`);
        event.clipboardData.setData('text/plain', data.map(line=>line.map(display).join('\t')).join('\n'));
        event.clipboardData.setData('application/x-hrla-attendance-grid', JSON.stringify(data));
        if(cut) apply(changes, 'Selected cells cut.');
    }
    root.addEventListener('copy', event => clipboard(event, false));
    root.addEventListener('cut', event => clipboard(event, true));
    root.addEventListener('paste', event => {
        const cell = event.target.closest('[data-grid-cell]'); if (!cell) return;
        const text = event.clipboardData.getData('text/plain');
        if (event.target.matches('[data-clock-hour],[data-clock-minute]') && /^\d{1,2}$/.test(text)) return;
        event.preventDefault();
        try {
            const exact = event.clipboardData.getData('application/x-hrla-attendance-grid');
            const data = exact ? JSON.parse(exact) : text.replace(/\r\n?/g,'\n').replace(/\n$/, '').split('\n').map(line=>line.split('\t'));
            if (!Array.isArray(data) || data.length>50 || data.some(line=>!Array.isArray(line) || line.length>4 || line.some(value=>typeof value!=='string'))) throw new Error('Paste a range of up to 50 rows and four columns.');
            const start=coords(cell), changes=[];
            if(start.r+data.length>rows.length || data.some(line=>start.c+line.length>4)) throw new Error('The pasted range exceeds this page or the four editable columns. No cells were changed.');
            data.forEach((line,r)=>line.forEach((raw,c)=>{
                const row=rows[start.r+r], field=fields[start.c+c];
                if(!row.querySelector('[data-import-include]').checked) throw new Error('Include the destination rows before pasting. No cells were changed.');
                changes.push({row,field,text:convert(row,field,raw)});
            }));
            apply(changes, `${changes.length} cells pasted.`);
        } catch(error) { message(error.message); }
    });
    const action=toolbar.querySelector('[data-sheet-action]');
    action.addEventListener('change',()=>{
        toolbar.querySelector('[data-sheet-mapped-source]').hidden=action.value==='source';
        toolbar.querySelector('[data-sheet-upload-source]').hidden=action.value!=='source';
    });
    toolbar.querySelector('[data-sheet-apply]').addEventListener('click',()=>{
        try {
            const from=toolbar.querySelector('[data-sheet-from]').value, to=toolbar.querySelector('[data-sheet-to]').value;
            if(action.value!=='source' && from===to) throw new Error('Choose two different columns.');
            const replace=toolbar.querySelector('[data-sheet-replace]').checked, changes=[];
            for(const row of rows) {
                if(!row.querySelector('[data-import-include]').checked) continue;
                if(action.value==='source' && Number(row.dataset.sourceCount)>1) throw new Error('Grouped punch logs have several source rows. Remap their source columns or edit the grouped cells directly.');
                const raw=action.value==='source' ? sources.get(Number(row.dataset.importRow))?.[toolbar.querySelector('[data-sheet-source]').value] ?? '' : value(row,from);
                const target=convert(row,to,raw), existing=value(row,to);
                if(action.value!=='swap' && existing && existing!==target && !replace) throw new Error('The destination contains data. Check Replace existing values to continue. No cells were changed.');
                changes.push({row,field:to,text:target});
                if(action.value==='move') changes.push({row,field:from,text:''});
                if(action.value==='swap') changes.push({row,field:from,text:convert(row,from,existing)});
            }
            if(!changes.length) throw new Error('Include at least one row on this page.');
            apply(changes, 'Column changes applied to included rows on this page.');
        } catch(error) { message(error.message); }
    });
    rows.forEach(row=>{
        const input=row.querySelector('[data-import-field=shift_date]');
        if(input.dataset.originalValue && !input.value) row.querySelector('[data-sheet-date-warning]').textContent=input.dataset.originalValue;
        row.querySelectorAll('[data-grid-cell]').forEach(cell=>{
            const box=document.createElement('div'); box.className='sheet-cell-editor';
            while(cell.firstChild) box.append(cell.firstChild);
            const done=document.createElement('button'); done.type='button'; done.className='btn'; done.textContent='Done'; done.addEventListener('click',()=>{closeEditor();cell.focus();}); box.append(done);
            const span=document.createElement('span'); span.className='sheet-cell-display'; cell.append(span,box);
        });
    });
    root.classList.add('sheet-ready'); renderCells();
})();
