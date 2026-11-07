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
        return Array.from('/' + path.replace(/^\/+/, '').split('/').map(segment => /[\x00-\x1f\x7f@?#]/.test(segment) || /%[a-f0-9]{2}/i.test(segment) || /^\d+$/.test(segment) || /^[a-f0-9-]{32,}$/i.test(segment) || Array.from(segment).length > 80 ? ':redacted' : segment).join('/')).slice(0, 512).join('');
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
    const campaignValue = value => !value || /[\x00-\x1f\x7f@\\/?#=&]/.test(value) || /[a-f0-9]{32,}/i.test(value) ? null : value.trim().slice(0, 100);
    function send(type, path, name, extra) {
        if (!allowed || blocked()) return;
        const cleaned = normalize(path || script.dataset.path || location.pathname);
        if (type === 'pageview' && cleaned === lastPath) return;
        const rules = (script.dataset.excludePaths || '').split(',').filter(Boolean);
        if (rules.some(rule => rule.endsWith('*') ? cleaned.startsWith(rule.slice(0, -1)) : cleaned === rule)) return;
        const payload = { v: 1, site, type, mode, host: location.hostname, path: cleaned, consent: given, ref: null };
        if (/Macintosh/i.test(navigator.userAgent || '') && navigator.maxTouchPoints > 1) payload.device_hint = 'tablet';
        if (type === 'event') { payload.event_name = name; Object.assign(payload, extra); }
        try {
            const ref = new URL(document.referrer);
            if (['http:', 'https:'].includes(ref.protocol) && ref.hostname !== location.hostname) payload.ref = ref.protocol + '//' + ref.hostname;
        } catch (_) { /* No referrer or an unsupported address. */ }
        try {
            const params = new URL(location.href).searchParams;
            ['utm_source', 'utm_medium', 'utm_campaign'].forEach(key => { payload[key] = campaignValue(params.get(key)); });
        } catch (_) { /* Invalid location cannot prevent collection. */ }
        if (mode === 'sessions') {
            payload.session = session();
            if (!payload.session) return;
        }
        if (type === 'pageview') lastPath = cleaned;
        try {
            window.fetch(endpoint, { method: 'POST', mode: 'cors', credentials: 'omit', referrerPolicy: 'no-referrer', keepalive: true, headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, body: JSON.stringify(payload) }).then(response => { const cut = debug && response && response.headers && response.headers.get('X-Perysee-Truncated'); if (cut) warn('the server shortened the properties (' + cut + ')'); }).catch(() => {});
        } catch (_) { /* Analytics must never break the host page. */ }
    }
    function pageview(path) { send('pageview', path); }
    const debug = script.dataset.debug === 'true';
    const warn = message => { if (debug && window.console) window.console.warn('lueur: ' + message); };
    function track(name, options) {
        if (typeof name !== 'string' || !/^[a-zA-Z][a-zA-Z0-9_.:-]{0,63}$/.test(name)) return warn('event name ignored');
        const extra = {};
        if (options && typeof options === 'object') {
            const { value, props, id } = options;
            if (value !== undefined) {
                if (typeof value === 'number' && value >= 0 && value <= 1e9) extra.value = value;
                else warn('value must be a number between 0 and 1000000000');
            }
            if (id !== undefined) {
                if (/^[\x21-\x7e]{1,64}$/.test(typeof id === 'number' ? String(id) : id)) extra.id = String(id);
                else warn('id must be 1 to 64 printable characters without spaces');
            }
            if (props !== undefined) {
                const kept = Object.create(null);
                let count = 0;
                if (props && typeof props === 'object' && !Array.isArray(props)) {
                    Object.keys(props).forEach(raw => {
                        const key = raw.trim().toLowerCase();
                        const item = props[raw];
                        const text = ['string', 'number', 'boolean'].includes(typeof item) ? String(item).trim() : '';
                        if (!/^[a-z][a-z0-9_]{0,31}$/.test(key) || kept[key] !== undefined || !text || Array.from(text).length > 64) return warn('property "' + raw + '" ignored');
                        if (count >= 5) return warn('property "' + raw + '" ignored: at most 5 properties');
                        kept[key] = text;
                        count++;
                    });
                } else warn('props must be an object');
                if (count) extra.props = kept;
            }
        }
        send('event', null, name, extra);
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
        track,
        push(command) {
            if (Array.isArray(command) && ['consent', 'pageview', 'track'].includes(command[0])) api[command[0]](...command.slice(1));
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
