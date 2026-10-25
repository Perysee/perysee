import { test } from 'node:test';
import assert from 'node:assert/strict';

const gateway = await import('../../infra/downloads/worker.mjs');
const environment = { GITHUB_REPOSITORY: 'mattrbdr/perysee', GITHUB_TOKEN: 'private-test-token' };
const script = '#!/usr/bin/env bash\necho installer\n';
function upstream() {
    const calls = [];
    const fetch = async (url, options = {}) => {
        calls.push({ url, options });
        if (url.includes('/releases/latest') || url.includes('/releases/tags/')) {
            return Response.json({ tag_name: 'v1.2.3', draft: false, prerelease: false,
                assets: [{ name: 'install.sh', id: 1 }, { name: 'perysee.zip', id: 2 }, { name: 'SHA256SUMS', id: 3 }, { name: 'install.php', id: 4 }] });
        }
        if (url.endsWith('/releases/assets/1')) return new Response(script);
        if (url.endsWith('/releases/assets/2')) return new Response(null, { status: 302, headers: { location: 'https://release-assets.githubusercontent.com/signed-file' } });
        if (url === 'https://release-assets.githubusercontent.com/signed-file') return new Response('ZIP_CONTENT');
        if (url.endsWith('/releases/assets/3')) return new Response('CHECKSUM_CONTENT');
        throw new Error(`Unexpected upstream request: ${url}`);
    };
    return { fetch, calls };
}
test('public curl endpoint serves the installer pinned to the public release gateway', async () => {
    const mock = upstream();
    const response = await gateway.handleDownload(new Request('https://get.perysee.eu/'), environment, {}, mock);
    assert.equal(response.status, 200);
    assert.match(response.headers.get('content-type'), /shellscript/);
    const body = await response.text();
    assert.match(body, /PERYSEE_DOWNLOAD_URL='https:\/\/get\.perysee\.eu\/releases\/v1\.2\.3'/);
    assert.ok(body.endsWith(script));
    assert.equal(body.includes(environment.GITHUB_TOKEN), false);
    assert.equal(mock.calls[0].options.headers.Authorization, `Bearer ${environment.GITHUB_TOKEN}`);
});
test('public release ZIP is streamed without disclosing the token to clients or asset redirect hosts', async () => {
    const mock = upstream();
    const response = await gateway.handleDownload(new Request('https://get.perysee.eu/releases/v1.2.3/perysee.zip'), environment, {}, mock);
    assert.equal(response.status, 200);
    assert.match(response.headers.get('cache-control'), /immutable/);
    assert.equal(await response.text(), 'ZIP_CONTENT');
    const redirected = mock.calls.find(call => call.url.includes('signed-file'));
    assert.equal(redirected.options.headers?.Authorization, undefined);
});
test('manual ZIP download resolves to a public versioned link', async () => {
    const mock = upstream();
    const response = await gateway.handleDownload(new Request('https://get.perysee.eu/perysee.zip'), environment, {}, mock);
    assert.equal(response.status, 302);
    assert.equal(response.headers.get('location'), 'https://get.perysee.eu/releases/v1.2.3/perysee.zip');
});
test('download gateway cannot expose repository files, arbitrary assets or API responses', async () => {
    const mock = upstream();
    for (const path of ['/README.md', '/.env', '/releases/v1.2.3/source.zip', '/repos/mattrbdr/perysee', '/releases/v1.2.3/../../README.md']) {
        const response = await gateway.handleDownload(new Request(`https://get.perysee.eu${path}`), environment, {}, mock);
        assert.equal(response.status, 404, path);
    }
    assert.equal(mock.calls.length, 0);
    const post = await gateway.handleDownload(new Request('https://get.perysee.eu/', { method: 'POST' }), environment, {}, mock);
    assert.equal(post.status, 405);
});
test('unavailable private releases return a generic error without private GitHub details', async () => {
    const response = await gateway.handleDownload(new Request('https://get.perysee.eu/'), environment, {}, {
        fetch: async () => new Response(`Secret: ${environment.GITHUB_TOKEN}`, { status: 404 })
    });
    assert.equal(response.status, 503);
    assert.equal((await response.text()).includes(environment.GITHUB_TOKEN), false);
    assert.equal(response.headers.get('cache-control'), 'no-store');
});

test('unexpected redirect destinations are rejected before any token or asset is forwarded', async () => {
    const mock = upstream();
    const fetched = [];
    const response = await gateway.handleDownload(new Request('https://get.perysee.eu/releases/v1.2.3/perysee.zip'), environment, {}, {
        fetch: async (url, options) => {
            fetched.push(url);
            if (url.endsWith('/assets/2')) return new Response(null, { status: 302, headers: { location: 'https://untrusted.example/file' } });
            return mock.fetch(url, options);
        }
    });
    assert.equal(response.status, 503);
    assert.equal(fetched.some(url => url.includes('untrusted.example')), false);
});

test('public responses are cached and HEAD requests return headers without a response body', async () => {
    const mock = upstream();
    const stored = new Map();
    const pending = [];
    const cache = { match: async key => stored.get(key.url)?.clone(), put: async (key, response) => { stored.set(key.url, response); } };
    const context = { waitUntil: promise => pending.push(promise) };
    const first = await gateway.handleDownload(new Request('https://get.perysee.eu/'), environment, context, { ...mock, cache });
    assert.equal(first.status, 200);
    await Promise.all(pending);
    const count = mock.calls.length;
    const head = await gateway.handleDownload(new Request('https://get.perysee.eu/', { method: 'HEAD' }), environment, context, { ...mock, cache });
    assert.equal(head.status, 200);
    assert.match(head.headers.get('content-type'), /shellscript/);
    assert.equal(await head.text(), '');
    assert.equal(mock.calls.length, count);
});
