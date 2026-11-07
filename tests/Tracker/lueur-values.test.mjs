import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { runInNewContext } from 'node:vm';
const sources = ['resources/js/lueur.js', 'public/tracker/lueur.v1.js'];
const site = 'c5d09ee1-e154-4625-b97c-6c8f8b27f103';
function browser(source, { debug = false, headers = {}, required = false } = {}) {
    const requests = [], warnings = [];
    const document = { currentScript: { dataset: { site, mode: 'pageviews', consentRequired: String(required), ...(debug ? { debug: 'true' } : {}) }, src: 'https://stats.example.com/lueur.js' } };
    Object.defineProperty(document, 'cookie', { get() { return ''; }, set() {} });
    const location = { protocol: 'https:', hostname: 'example.com', pathname: '/', href: 'https://example.com/' };
    const context = { document, location, history: { pushState() {}, replaceState() {} }, navigator: {}, URL, Uint8Array, Date };
    context.window = { lueur: undefined, console: { warn: message => warnings.push(message) }, crypto: { getRandomValues() {} }, addEventListener() {},
        fetch(url, options) { requests.push({ url, options, data: JSON.parse(options.body) }); return Promise.resolve({ status: 204, headers: { get: name => headers[name] ?? null } }); } };
    runInNewContext(source, context);
    return { api: context.window.lueur, requests, warnings };
}
for (const file of sources) {
    const source = readFileSync(file, 'utf8');
    test(`${file}: track(name) sends exactly the previous payload`, () => {
        const page = browser(source);
        page.api.track('signup');
        assert.deepEqual(Object.keys(page.requests.at(-1).data), ['v', 'site', 'type', 'mode', 'host', 'path', 'consent', 'ref', 'event_name', 'utm_source', 'utm_medium', 'utm_campaign']);
        page.api.track('signup', undefined); page.api.track('signup', null); page.api.track('signup', {});
        for (const request of page.requests.slice(1)) assert.deepEqual(Object.keys(request.data), Object.keys(page.requests.at(-1).data));
        assert.equal(page.requests.length, 5);
    });
    test(`${file}: value, properties and id travel with the event`, () => {
        const page = browser(source);
        page.api.track('purchase', { value: 49.9, props: { Payment: 'card', gift: true, tier: 3 }, id: 'order-1001' });
        const data = page.requests.at(-1).data;
        assert.equal(data.value, 49.9);
        assert.deepEqual(data.props, { payment: 'card', gift: 'true', tier: '3' });
        assert.equal(data.id, 'order-1001');
        assert.equal(data.event_name, 'purchase');
    });
    test(`${file}: the queue form carries the options`, () => {
        const page = browser(source);
        page.api.push(['track', 'purchase', { value: 10, props: { plan: 'pro' } }]);
        assert.deepEqual(page.requests.at(-1).data.props, { plan: 'pro' });
        assert.equal(page.requests.at(-1).data.value, 10);
    });
    test(`${file}: a value of zero is a value`, () => {
        const page = browser(source);
        page.api.track('purchase', { value: 0 });
        assert.equal(page.requests.at(-1).data.value, 0);
    });
    test(`${file}: an invalid value, property or id is dropped and the event is still sent`, () => {
        const page = browser(source);
        for (const value of [-1, NaN, Infinity, 1e9 + 1, '5', null, {}, [1]]) {
            page.api.track('purchase', { value });
            assert.equal('value' in page.requests.at(-1).data, false, String(value));
        }
        page.api.track('purchase', { id: 'has space' });
        assert.equal('id' in page.requests.at(-1).data, false);
        page.api.track('purchase', { id: 'x'.repeat(65) });
        assert.equal('id' in page.requests.at(-1).data, false);
        page.api.track('purchase', { props: ['a', 'b'] });
        assert.equal('props' in page.requests.at(-1).data, false);
        page.api.track('purchase', { props: 'text' });
        assert.equal('props' in page.requests.at(-1).data, false);
        page.api.track('purchase', { props: { '1bad': 'x', 'has space': 'x', ok: 'fine', long: 'v'.repeat(65), empty: '  ', nested: { a: 1 }, nothing: null, Ok: 'again', constructor: 'x' } });
        assert.deepEqual(page.requests.at(-1).data.props, { ok: 'fine', constructor: 'x' });
        assert.equal(page.requests.length, 1 + 8 + 2 + 2 + 1); // the pageview, then one event per call
    });
    test(`${file}: at most five properties and 64 characters are sent`, () => {
        const page = browser(source);
        page.api.track('purchase', { props: { a: '1', b: '2', c: '3', d: '4', e: '5', f: '6', g: 'é'.repeat(64) } });
        assert.deepEqual(Object.keys(page.requests.at(-1).data.props), ['a', 'b', 'c', 'd', 'e']);
        page.api.track('purchase', { props: { label: 'é'.repeat(64) } });
        assert.equal(Array.from(page.requests.at(-1).data.props.label).length, 64);
    });
    test(`${file}: nothing is sent without consent or against a privacy signal, whatever the options`, () => {
        const blocked = browser(source, { required: true });
        blocked.api.track('purchase', { value: 5, props: { a: 'b' } });
        assert.equal(blocked.requests.length, 0);
    });
    test(`${file}: warnings appear only with data-debug`, async () => {
        const quiet = browser(source);
        quiet.api.track('purchase', { value: -1, props: { email: 'x', '1': 'y' } });
        assert.deepEqual(quiet.warnings, []);
        const loud = browser(source, { debug: true, headers: { 'X-Perysee-Truncated': 'props=1;reason=redacted' } });
        loud.api.track('purchase', { value: -1, props: { '1bad': 'y' }, id: ' ' });
        loud.api.track('bad name!');
        await new Promise(resolve => setImmediate(resolve));
        assert.ok(loud.warnings.length >= 5, loud.warnings.join('|'));
        assert.ok(loud.warnings.every(message => message.startsWith('lueur: ')));
        assert.ok(loud.warnings.some(message => message.includes('props=1;reason=redacted')));
        assert.equal(loud.requests.length, 2);
    });
    test(`${file}: a response without headers never breaks the page`, async () => {
        const page = browser(source, { debug: true });
        page.api.track('purchase', { value: 1 });
        await new Promise(resolve => setImmediate(resolve));
        assert.deepEqual(page.warnings, []);
    });
}
test('the built tracker stays under 5 KiB gzip', () => {
    const size = gzipSync(readFileSync('public/tracker/lueur.v1.js'), { level: 9 }).byteLength;
    assert.ok(size < 5120, `${size} bytes`);
    assert.ok(readFileSync('public/tracker/lueur.v1.js.gz').byteLength < 5120);
});
test('the built tracker matches the source', () => {
    const built = readFileSync('public/tracker/lueur.v1.js', 'utf8');
    assert.ok(built.includes('X-Perysee-Truncated'));
});
