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
    const document = { currentScript: { dataset: { site, mode, consentRequired: String(required) }, src: 'https://stats.example.com/lueur.js' } };
    Object.defineProperty(document, 'cookie', {
        get() { reads++; if(cookiesBlocked) return ''; return [...jar].map(([key, value]) => key + '=' + value).join('; '); },
        set(value) { writes++; if(cookiesBlocked) return; const [pair] = value.split(';'); const index = pair.indexOf('='); if (value.includes('Max-Age=0')) jar.delete(pair.slice(0,index)); else jar.set(pair.slice(0,index),pair.slice(index+1)); },
    });
    const location = { protocol: 'https:', hostname: 'example.com', pathname: '/' };
    const history = { pushState(_state, _title, path) { location.pathname = path.split(/[?#]/)[0]; }, replaceState(_state, _title, path) { location.pathname = path.split(/[?#]/)[0]; } };
    const context = { document, location, history, navigator: { globalPrivacyControl: signal }, URL, Uint8Array, Date: class extends Date { static now() { return time; } } };
    context.window = { lueur: queue, crypto: { getRandomValues(bytes) { for(let i=0;i<bytes.length;i++) bytes[i] = (entropy++ % 256); } }, fetch(url, options) { requests.push({ url, options, data: JSON.parse(options.body) }); return fail ? Promise.reject(new Error('offline')) : Promise.resolve({status:204}); }, addEventListener() {} };
    runInNewContext(source, context);
    return { context, jar, requests, reads: () => reads, writes: () => writes, advance: milliseconds => time += milliseconds, api: context.window.lueur };
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
