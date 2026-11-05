(() => {
    document.querySelectorAll('[data-month-day]').forEach(field => {
        const root = field.closest('[data-holiday-date]');
        const toggle = root.querySelector('.holiday-date-toggle');
        const picker = document.createElement('div');
        picker.id = field.id + '-picker';
        picker.className = 'holiday-date-picker';
        picker.setAttribute('popover', 'auto');
        picker.setAttribute('role', 'dialog');
        picker.setAttribute('aria-label', 'Choose holiday day and month');
        picker.innerHTML = '<div class="holiday-picker-heading"><button type="button" data-previous aria-label="Previous month">‹</button><select aria-label="Month"></select><button type="button" data-next aria-label="Next month">›</button></div><div class="holiday-picker-days" role="group" aria-label="Days"></div><p>Repeats each year</p>';
        root.append(picker);
        toggle.setAttribute('aria-controls', picker.id);
        toggle.setAttribute('popovertarget', picker.id);
        const monthSelect = picker.querySelector('select');
        const days = picker.querySelector('.holiday-picker-days');
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        months.forEach((month, index) => monthSelect.add(new Option(month, index + 1)));
        let month = 1;
        const parsed = () => /^(\d{2})\/(\d{2})$/.exec(field.value);
        function position() {
            if (!picker.matches(':popover-open')) return;
            const anchor = field.getBoundingClientRect();
            const width = picker.offsetWidth, height = picker.offsetHeight;
            picker.style.left = Math.max(8, Math.min(anchor.left, innerWidth - width - 8)) + 'px';
            picker.style.top = Math.max(8, anchor.bottom + height + 8 <= innerHeight ? anchor.bottom + 6 : anchor.top - height - 6) + 'px';
        }
        function close(focus = false) {
            if (picker.matches(':popover-open')) picker.hidePopover();
            if (focus) field.focus();
        }
        function render(focusDay) {
            const focusedDay = days.contains(document.activeElement);
            monthSelect.value = month;
            days.replaceChildren();
            const current = parsed();
            const length = new Date(2000, month, 0).getDate();
            for (let day = 1; day <= length; day++) {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = day;
                button.dataset.day = day;
                button.setAttribute('aria-label', day + ' ' + months[month - 1]);
                button.setAttribute('aria-pressed', String(Boolean(current && Number(current[1]) === day && Number(current[2]) === month)));
                button.addEventListener('click', () => {
                    field.value = String(day).padStart(2, '0') + '/' + String(month).padStart(2, '0');
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                    close(true);
                });
                days.append(button);
            }
            if (focusDay) days.children[Math.min(focusDay, length) - 1].focus();
            else if (focusedDay) monthSelect.focus();
            position();
        }
        function open(focus = false) {
            const current = parsed();
            month = current && Number(current[2]) >= 1 && Number(current[2]) <= 12 ? Number(current[2]) : new Date().getMonth() + 1;
            render();
            if (!picker.matches(':popover-open')) picker.showPopover();
            position();
            if (focus) days.children[Math.max(0, Math.min(Number(current?.[1]) || 1, days.children.length) - 1)].focus();
        }
        monthSelect.addEventListener('change', () => { month = Number(monthSelect.value); render(); });
        picker.querySelector('[data-previous]').addEventListener('click', () => { month = month === 1 ? 12 : month - 1; render(); });
        picker.querySelector('[data-next]').addEventListener('click', () => { month = month === 12 ? 1 : month + 1; render(); });
        picker.addEventListener('toggle', () => toggle.setAttribute('aria-expanded', String(picker.matches(':popover-open'))));
        picker.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); return; }
            if (!event.target.dataset.day) return;
            const day = Number(event.target.dataset.day);
            const next = { ArrowLeft: day - 1, ArrowRight: day + 1, ArrowUp: day - 7, ArrowDown: day + 7, Home: 1, End: days.children.length }[event.key];
            if (next !== undefined) { event.preventDefault(); days.children[Math.max(1, Math.min(next, days.children.length)) - 1].focus(); }
        });
        toggle.addEventListener('click', event => {
            event.preventDefault();
            if (picker.matches(':popover-open')) close(true); else open(true);
        });
        field.addEventListener('click', () => open());
        field.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') { event.preventDefault(); open(true); }
            if (event.key === 'Escape' && picker.matches(':popover-open')) { event.preventDefault(); event.stopPropagation(); close(); }
        });
        root.addEventListener('focusout', () => setTimeout(() => { if (!root.contains(document.activeElement)) close(); }, 0));
        window.addEventListener('resize', position);
        document.addEventListener('scroll', position, true);
        field.form.addEventListener('reset', () => close());
        field.addEventListener('input', () => {
            const before = field.value.slice(0, field.selectionStart).replace(/\D/g, '').length;
            const digits = field.value.replace(/\D/g, '').slice(0, 4);
            field.value = digits.slice(0, 2) + (digits.length > 2 ? '/' + digits.slice(2) : '');
            const caret = Math.min(field.value.length, before + (before > 2 ? 1 : 0));
            field.setSelectionRange(caret, caret);
        });
    });
})();
