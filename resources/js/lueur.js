/* Lueur · Perysee's small, first-party audience light. */
(() => {
    'use strict';
    const script = document.currentScript;
    if (!script || (window.lueur && window.lueur.version)) return;
    const site = script.dataset.site;
    if (!/^[a-f0-9-]{36}$/i.test(site || '')) return;
    const mode = script.dataset.mode === 'sessions' ? 'sessions' : 'pageviews';
    const required = script.dataset.consentRequired !== 'false';
    const endpoint = new URL('api/collect', script.src).href;
    const cookieName = 'perysee_lueur_' + site.replace(/-/g, '');
    const queue = Array.isArray(window.lueur) ? window.lueur.slice() : [];
    const idle = 1800000;
    const lifetime = 86400000;
    let allowed = !required;
    let given = false;
    let lastPath = null;
    const blocked = () => navigator.doNotTrack === '1' || navigator.globalPrivacyControl === true || window.peryseeExclude === true;
    const normalize = value => {
        let path = String(value).split(/[?#]/)[0];
        try { path = decodeURIComponent(path); } catch (_) { /* Keep invalid escapes as literal text. */ }
        return ('/' + path.replace(/^\/+/, '').split('/').map(segment => /[\x00-\x1f\x7f@]/.test(segment) || /^\d+$/.test(segment) || /^[a-f0-9-]{32,}$/i.test(segment) || segment.length > 80 ? ':redacted' : segment).join('/')).slice(0, 512);
    };
    function readCookie() {
        const value = document.cookie.split('; ').find(part => part.startsWith(cookieName + '='));
        return value ? JSON.parse(decodeURIComponent(value.slice(cookieName.length + 1))) : null;
    }
    function session() {
        try {
            const now = Date.now();
            let record;
            try { record = readCookie(); } catch (_) { record = null; }
            if (!record || !/^[a-f0-9]{32}$/.test(record.id) || !Number.isFinite(record.last) || !Number.isFinite(record.born) || now - record.last >= idle || now - record.born >= lifetime || record.born > now || record.last > now) {
                const random = new Uint8Array(16);
                window.crypto.getRandomValues(random);
                record = { id: Array.from(random, byte => byte.toString(16).padStart(2, '0')).join(''), born: now, last: now };
            }
            record.last = now;
            const seconds = Math.min(1800, Math.ceil((record.born + lifetime - now) / 1000));
            document.cookie = cookieName + '=' + encodeURIComponent(JSON.stringify(record)) + '; Path=/; SameSite=Lax; Max-Age=' + seconds + (location.protocol === 'https:' ? '; Secure' : '');
            return readCookie()?.id === record.id ? record.id : null;
        } catch (_) { return null; }
    }
    function pageview(path) {
        if (!allowed || blocked()) return;
        const cleaned = normalize(path || script.dataset.path || location.pathname);
        if (cleaned === lastPath) return;
        const payload = { v: 1, site, type: 'pageview', mode, host: location.hostname, path: cleaned, consent: given };
        if (mode === 'sessions') {
            payload.session = session();
            if (!payload.session) return;
        }
        lastPath = cleaned;
        try {
            window.fetch(endpoint, { method: 'POST', mode: 'cors', credentials: 'omit', referrerPolicy: 'no-referrer', keepalive: true, headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, body: JSON.stringify(payload) }).catch(() => {});
        } catch (_) { /* Analytics must never break the host page. */ }
    }
    const api = {
        version: 1,
        consent(value) {
            given = value === true;
            allowed = given;
            if (!allowed) {
                lastPath = null;
                if (mode === 'sessions') {
                    try { document.cookie = cookieName + '=; Path=/; SameSite=Lax; Max-Age=0' + (location.protocol === 'https:' ? '; Secure' : ''); } catch (_) { /* A blocked cookie cannot be cleared. */ }
                }
            } else pageview();
        },
        pageview,
        push(command) {
            if (Array.isArray(command) && ['consent', 'pageview'].includes(command[0])) api[command[0]](...command.slice(1));
        }
    };
    window.lueur = api;
    ['pushState', 'replaceState'].forEach(method => {
        const original = history[method];
        try { history[method] = function (...args) {
            const result = original.apply(this, args);
            pageview();
            return result;
        }; } catch (_) { /* Some routers protect their history methods. */ }
    });
    window.addEventListener('popstate', () => pageview());
    queue.forEach(command => api.push(command));
    pageview();
})();
