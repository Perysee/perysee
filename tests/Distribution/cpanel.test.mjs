import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, copyFileSync, rmSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { once } from 'node:events';

async function fixture(t) {
    const directory = mkdtempSync(join(tmpdir(), 'perysee-cpanel-'));
    let server;
    t.after(async () => {
        if (server && server.exitCode === null) {
            const ended = once(server, 'exit');
            server.kill();
            await ended;
        }
        rmSync(directory, { recursive: true, force: true });
    });
    const publicRoot = join(directory, 'public');
    const cache = join(directory, 'private-cache');
    mkdirSync(publicRoot);
    mkdirSync(join(cache, 'releases/v1.2.3'), { recursive: true });
    copyFileSync(resolve('infra/downloads/Gateway.php'), join(directory, 'Gateway.php'));
    copyFileSync(resolve('infra/downloads/public/index.php'), join(publicRoot, 'index.php'));
    writeFileSync(join(directory, 'config.php'), `<?php return ['origin' => 'https://get.perysee.eu', 'repository' => 'mattrbdr/perysee', 'token' => 'private-http-test-token', 'cache_path' => __DIR__.'/private-cache'];`);
    writeFileSync(join(cache, 'latest.json'), JSON.stringify({ version: 'v1.2.3', at: Math.floor(Date.now() / 1000) }));
    writeFileSync(join(cache, 'releases/v1.2.3/install.sh'), '#!/bin/bash\necho installer\n');
    writeFileSync(join(cache, 'releases/v1.2.3/install.php'), '<?php echo "MUST_NOT_EXECUTE";');
    writeFileSync(join(cache, 'releases/v1.2.3/perysee.zip'), 'ZIP_CONTENT');
    const listener = createServer();
    listener.listen(0, '127.0.0.1');
    await once(listener, 'listening');
    const port = listener.address().port;
    await new Promise(resolve => listener.close(resolve));
    server = spawn(process.env.PHP_BINARY || 'php', ['-S', `127.0.0.1:${port}`, '-t', publicRoot, join(publicRoot, 'index.php')], { stdio: ['ignore', 'ignore', 'pipe'] });
    let logs = '';
    server.stderr.on('data', chunk => { logs += chunk; });
    const origin = `http://127.0.0.1:${port}`;
    for (let attempt = 0; attempt < 100; attempt++) {
        try {
            await fetch(`${origin}/`, { method: 'HEAD' });
            return { origin, directory, publicRoot, cache, logs: () => logs };
        } catch {
            if (server.exitCode !== null) break;
            await new Promise(resolve => setTimeout(resolve, 20));
        }
    }
    assert.fail(`PHP download server did not start: ${logs}`);
}

test('cPanel PHP entrypoint serves the pinned shell, downloads PHP as data and ignores the request host', async t => {
    const f = await fixture(t);
    const response = await fetch(`${f.origin}/`, { headers: { Host: 'untrusted.example' } });
    assert.equal(response.status, 200);
    assert.match(response.headers.get('content-type'), /shellscript/);
    assert.match(await response.text(), /PERYSEE_DOWNLOAD_URL='https:\/\/get\.perysee\.eu\/releases\/v1\.2\.3'/);
    const php = await fetch(`${f.origin}/releases/v1.2.3/install.php`);
    assert.equal(php.headers.get('content-type'), 'application/octet-stream');
    assert.equal(php.headers.get('content-disposition'), 'attachment; filename="install.php"');
    assert.equal(await php.text(), '<?php echo "MUST_NOT_EXECUTE";');
    const head = await fetch(`${f.origin}/releases/v1.2.3/perysee.zip`, { method: 'HEAD' });
    assert.equal(head.status, 200);
    assert.equal(head.headers.get('content-length'), '11');
    assert.equal(await head.text(), '');
});

test('cPanel endpoint rejects private paths and fails closed when configuration is missing', async t => {
    const f = await fixture(t);
    for (const path of ['/config.php', '/Gateway.php', '/.env', '/private-cache/latest.json', '/?token=secret']) {
        const response = await fetch(f.origin + path);
        assert.equal(response.status, 404, path);
        assert.doesNotMatch(await response.text(), /private-http-test-token/);
    }
    const post = await fetch(`${f.origin}/`, { method: 'POST' });
    assert.equal(post.status, 405);
    rmSync(join(f.directory, 'config.php'));
    const response = await fetch(`${f.origin}/`);
    assert.equal(response.status, 503);
    assert.equal(response.headers.get('cache-control'), 'no-store');
    assert.equal(await response.text(), 'Perysee downloads are temporarily unavailable. Please try again later.\n');
});

test('Apache configuration routes every asset through the PHP controller', () => {
    const rules = readFileSync(resolve('infra/downloads/public/.htaccess'), 'utf8');
    assert.match(rules, /Options -Indexes -MultiViews/);
    assert.match(rules, /RewriteRule \^ index\.php \[L\]/);
    assert.doesNotMatch(rules, /REQUEST_FILENAME.*!-f/);
});
