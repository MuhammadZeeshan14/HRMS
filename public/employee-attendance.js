(() => {
    const root = document.querySelector('[data-live-attendance]');
    if (!root) return;
    const buttons = [...root.querySelectorAll('[data-action]')];
    const message = root.querySelector('[data-punch-message]');
    const storageKey = `hrla-live-punch-${root.dataset.employee}`;
    const confirmationKey = `${storageKey}-confirmation`;
    let busy = false;
    let pending;
    try { pending = JSON.parse(sessionStorage.getItem(storageKey) || 'null'); } catch { pending = null; }
    const tell = (text, state = '') => { message.textContent = text; message.dataset.state = state; };
    const confirmed = sessionStorage.getItem(confirmationKey);
    if (confirmed) { tell(confirmed, 'success'); sessionStorage.removeItem(confirmationKey); }
    if (pending) tell(`A ${pending.action === 'check_in' ? 'Check In' : 'Check Out'} submission needs confirmation. Press the same button to safely retry.`, 'error');
    const start = performance.now(), serverStart = Date.parse(root.dataset.now);
    const time = new Intl.DateTimeFormat('en-GB', {timeZone: root.dataset.timezone, hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:true});
    const date = new Intl.DateTimeFormat('en-GB', {timeZone: root.dataset.timezone, weekday:'short', day:'2-digit', month:'short', year:'numeric'});
    const day = new Intl.DateTimeFormat('en-CA', {timeZone: root.dataset.timezone, year:'numeric', month:'2-digit', day:'2-digit'});
    const tick = () => {
        const now = new Date(serverStart + performance.now() - start);
        root.querySelector('[data-live-clock]').textContent = time.format(now);
        root.querySelector('[data-live-date]').textContent = date.format(now);
        const parts = Object.fromEntries(day.formatToParts(now).map(p => [p.type,p.value]));
        if (`${parts.year}-${parts.month}-${parts.day}` !== root.dataset.today && !busy) location.reload();
    };
    tick(); setInterval(tick, 1000);
    for (const button of buttons) button.addEventListener('click', async () => {
        if (busy) return;
        const action = button.dataset.action;
        if (pending && pending.action !== action) { tell('Retry the previous action first so its saved status can be confirmed.', 'error'); return; }
        if (!navigator.geolocation) { tell('This browser cannot capture location. Use a current browser on HTTPS or localhost, enable location access, and retry.', 'error'); return; }
        busy = true; buttons.forEach(b => b.disabled = true);
        tell('Capturing your location… Please keep this page open.');
        let submitted = false;
        try {
            const position = await new Promise((resolve,reject) => navigator.geolocation.getCurrentPosition(resolve,reject,{enableHighAccuracy:true, timeout:20000, maximumAge:0}));
            const {latitude, longitude, accuracy} = position.coords;
            root.querySelector('[data-captured-location]').textContent = `${latitude.toFixed(6)}, ${longitude.toFixed(6)}`;
            root.querySelector('[data-location-accuracy]').textContent = `Browser-reported accuracy: ±${Math.round(accuracy)} m`;
            pending ||= {action, submission_id: crypto.randomUUID()};
            sessionStorage.setItem(storageKey, JSON.stringify(pending));
            tell('Saving your punch with the server timestamp…');
            submitted = true;
            const response = await fetch(root.dataset.endpoint, {method:'POST', credentials:'same-origin', headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':root.dataset.token}, body:JSON.stringify({...pending,latitude,longitude,accuracy})});
            const data = await response.json();
            if (!response.ok) {
                // Validation/authorization failures do not accept a punch. Network failures keep the retry key.
                if ([403,419,422,429].includes(response.status)) { pending = null; sessionStorage.removeItem(storageKey); }
                throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Punch could not be saved. Reload and retry.');
            }
            root.querySelector('[data-location-distance]').textContent = `${Number(data.event.distance_metres).toLocaleString()} m`;
            sessionStorage.removeItem(storageKey); pending = null;
            const saved = `${data.message} Distance from office: ${Number(data.event.distance_metres).toLocaleString()} m; accuracy ±${Math.round(data.event.accuracy)} m.`;
            tell(saved,'success'); sessionStorage.setItem(confirmationKey,saved);
            setTimeout(() => location.reload(), 1200);
        } catch (error) {
            const geo = {1:'Location permission was denied. Allow location for this site in your browser settings, then retry.',2:'Your location could not be determined. Check device location settings or move to an area with a better signal, then retry.',3:'Location capture timed out. Check device location settings and retry.'};
            tell(geo[error.code] || `${error.message || 'Connection failed.'}${submitted && pending ? ' Press the same button to safely retry; a saved punch will not be duplicated.' : ''}`, 'error');
            busy = false; buttons.forEach(b => b.disabled = false);
        }
    });
})();
