import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
const sources = ['resources/js/lueur.js', 'public/tracker/lueur.v1.js'];
let entropy = 0;
const site = 'c5d09ee1-e154-4625-b97c-6c8f8b27f103';
function browser(source, { mode = 'pageviews', required = true, jar = new Map(), signal = false, now = 100000000, fail = false, queue = undefined, cookiesBlocked = false } = {}) {
    let reads = 0, writes = 0, time = now;
    const requests = [];
    const listeners = {};
    const document = { currentScript: { dataset: { site, mode, consentRequired: String(required) }, src: 'https://stats.example.com/lueur.js' } };
    Object.defineProperty(document, 'cookie', {
        get() { reads++; if(cookiesBlocked) return ''; return [...jar].map(([key, value]) => key + '=' + value).join('; '); },
        set(value) { writes++; if(cookiesBlocked) return; const [pair] = value.split(';'); const index = pair.indexOf('='); if (value.includes('Max-Age=0')) jar.delete(pair.slice(0,index)); else jar.set(pair.slice(0,index),pair.slice(index+1)); },
    });
    const location = { protocol: 'https:', hostname: 'example.com', pathname: '/', href: 'https://example.com/' };
    const history = { pushState(_state, _title, path) { const url = new URL(path, location.href); location.pathname = url.pathname; location.href = url.href; }, replaceState(_state, _title, path) { const url = new URL(path, location.href); location.pathname = url.pathname; location.href = url.href; } };
    const context = { document, location, history, navigator: { globalPrivacyControl: signal }, URL, Uint8Array, Date: class extends Date { static now() { return time; } } };
    context.window = { lueur: queue, crypto: { getRandomValues(bytes) { for(let i=0;i<bytes.length;i++) bytes[i] = (entropy++ % 256); } }, fetch(url, options) { requests.push({ url, options, data: JSON.parse(options.body) }); return fail ? Promise.reject(new Error('offline')) : Promise.resolve({status:204}); }, addEventListener(name, callback) { listeners[name] = callback; } };
    runInNewContext(source, context);
    return { context, jar, requests, dispatch: name => listeners[name]?.(), reads: () => reads, writes: () => writes, advance: milliseconds => time += milliseconds, api: context.window.lueur };
}
for (const file of sources) {
    const source = readFileSync(file, 'utf8');
    test(`${file}: consent gates all collection and cookies`, () => {
        const page = browser(source, { mode: 'sessions' });
        assert.equal(page.requests.length, 0); assert.equal(page.reads(), 0); assert.equal(page.writes(), 0);
        page.api.consent(true);
        assert.equal(page.requests.length, 1); assert.equal(page.jar.size, 1);
        assert.equal(page.requests[0].options.credentials, 'omit');
        assert.equal(page.requests[0].options.referrerPolicy, 'no-referrer');
        page.api.consent(false); assert.equal(page.jar.size, 0);
        page.api.pageview('/other'); assert.equal(page.requests.length, 1);
    });
    test(`${file}: no-identifier mode never reads or writes cookies`, () => {
        const page = browser(source, { required:false });
        assert.equal(page.requests.length, 1); assert.equal(page.requests[0].data.consent,false);
        assert.equal(page.reads(),0); assert.equal(page.writes(),0); assert.equal(page.requests[0].data.session,undefined);
        page.context.history.pushState(null,'','/about?email=secret');
        page.api.pageview('/about');
        assert.equal(page.requests.length,2); assert.equal(page.requests[1].data.path,'/about');
    });
    test(`${file}: session persists across pages, expires after idle, and is bounded to 24h`, () => {
        const first = browser(source,{mode:'sessions',required:false});
        const second = browser(source,{mode:'sessions',required:false,jar:first.jar,now:100001000});
        assert.equal(first.requests[0].data.session, second.requests[0].data.session);
        second.advance(1800001); second.api.pageview('/new');
        assert.notEqual(second.requests[0].data.session,second.requests[1].data.session);
        second.advance(86400001); second.api.pageview('/later');
        assert.notEqual(second.requests[1].data.session,second.requests[2].data.session);
    });
    test(`${file}: privacy signals prevent collection even after explicit activation`, () => {
        const page = browser(source,{required:false,mode:'sessions',signal:true});
        page.api.consent(true); assert.equal(page.requests.length,0); assert.equal(page.reads(),0); assert.equal(page.writes(),0);
    });
    test(`${file}: obvious identifiers and sensitive URL parts are removed before transmission`, () => {
        const page = browser(source,{required:false});
        page.api.pageview('/users/123/a%40example.com?token=secret#secret');
        assert.equal(page.requests[1].data.path,'/users/:redacted/:redacted');
    });
    test(`${file}: a queued objection prevents automatic collection on the next page`, () => {
        const page = browser(source,{required:false,queue:[['consent',false]]});
        assert.equal(page.requests.length,0);
    });
    test(`${file}: blocked cookies never manufacture one session per page`, () => {
        const page = browser(source,{mode:'sessions',required:false,cookiesBlocked:true});
        page.api.pageview('/other'); assert.equal(page.requests.length,0);
    });
    test(`${file}: duplicate script execution produces no duplicate pageview`, () => {
        const page = browser(source,{required:false});
        runInNewContext(source,page.context); assert.equal(page.requests.length,1);
    });
    test(`${file}: network failures never cause unhandled page errors`, async () => {
        const page = browser(source,{required:false,fail:true});
        await new Promise(resolve => setImmediate(resolve)); assert.equal(page.requests.length,1);
    });
}

for (const file of sources) {
    const source = readFileSync(file, 'utf8');
    test(`${file}: custom events use consent, bounded names and queue commands`, () => {
        const page = browser(source);
        page.api.track('signup'); assert.equal(page.requests.length, 0);
        page.api.consent(true); page.api.track('signup');
        assert.equal(page.requests[1].data.type, 'event');
        assert.equal(page.requests[1].data.event_name, 'signup');
        page.api.push(['track', 'checkout']); assert.equal(page.requests[2].data.event_name, 'checkout');
        page.api.track('email@example.com'); page.api.track('a'.repeat(65));
        assert.equal(page.requests.length, 3);
        page.api.consent(false); page.api.track('blocked'); assert.equal(page.requests.length, 3);
    });
    test(`${file}: only referrer hostname and authorized campaign fields leave the page`, () => {
        const page = browser(source);
        page.context.document.referrer = 'https://search.example.org/private?email=a@example.com';
        page.context.location.href = 'https://example.com/?utm_source=news&utm_medium=email&utm_campaign=launch&email=private';
        page.api.consent(true);
        assert.equal(page.requests[0].data.ref, 'https://search.example.org');
        assert.equal(page.requests[0].data.utm_source, 'news');
        assert.equal(page.requests[0].data.utm_campaign, 'launch');
        assert.equal(JSON.stringify(page.requests[0].data).includes('private'), false);
    });
}

for (const file of sources) {
    const source = readFileSync(file, 'utf8');
    test(`${file}: shared canonical paths never decode private data twice`, () => {
        const page = browser(source, { required: false });
        for (const fixture of JSON.parse(readFileSync('tests/Fixtures/paths.json', 'utf8'))) {
            page.api.pageview('/separator');
            page.api.pageview(fixture.input);
            assert.equal(page.requests.at(-1).data.path, fixture.expected);
        }
    });
    test(`${file}: only the iPad desktop-mode hint is sent and no raw agent leaves the page`, () => {
        const page = browser(source);
        page.context.navigator.userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X) Safari/605';
        page.context.navigator.maxTouchPoints = 5;
        page.api.consent(true);
        assert.equal(page.requests.at(-1).data.device_hint, 'tablet');
        assert.equal(JSON.stringify(page.requests.at(-1).data).includes('Macintosh'), false);
    });
}

for (const file of sources) {
    const source = readFileSync(file, 'utf8');
    test(`${file}: SPA route hooks and popstate count one view per useful navigation`, () => {
        const page = browser(source, {required:false, queue:[['pageview','/']]});
        assert.equal(page.requests.length,1);
        page.context.history.pushState(null,'','/pricing?utm_source=chatgpt.com');
        page.api.pageview('/pricing');
        page.context.history.replaceState(null,'','/pricing?different=query');
        assert.equal(page.requests.length,2);
        assert.equal(page.requests[1].data.utm_source,'chatgpt.com');
        page.context.location.pathname='/'; page.context.location.href='https://example.com/';
        page.dispatch('popstate'); page.dispatch('popstate');
        assert.equal(page.requests.length,3);
    });
}
