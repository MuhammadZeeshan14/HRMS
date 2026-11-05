document.querySelectorAll('[data-hrla-clock]').forEach(group => {
    const hour = group.querySelector('[data-clock-hour]');
    const minute = group.querySelector('[data-clock-minute]');
    const period = group.querySelector('[data-clock-period]');
    const value = group.querySelector('[data-clock-value]');
    const sync = () => {
        const validHour = /^(0?[1-9]|1[0-2])$/.test(hour.value);
        const validMinute = /^[0-5]?\d$/.test(minute.value);
        hour.setCustomValidity(hour.value && !validHour ? 'Enter an hour from 1 to 12.' : '');
        minute.setCustomValidity(minute.value && !validMinute ? 'Enter minutes from 00 to 59.' : '');
        value.value = validHour && validMinute ? `${Number(hour.value)}:${minute.value.padStart(2,'0')} ${period.value}` : '';
        group.dispatchEvent(new CustomEvent('hrla:clock-change', {bubbles:true}));
    };
    [hour,minute].forEach(input => {
        input.addEventListener('keydown',event=>{ if(['e','E','+','-','.'].includes(event.key)) event.preventDefault(); });
        input.addEventListener('blur',()=>{ if(input.value && input.validity.valid) input.value=input.value.padStart(2,'0'); sync(); });
    });
    group.addEventListener('input',sync);
    period.addEventListener('change',sync);
});
