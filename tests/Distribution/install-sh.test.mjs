import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, chmodSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const script = resolve('scripts/install.sh');

// A fake PHP CLI that accepts the version probe (exit 0) or fails it (exit 1).
function fakePhp(path, recent) {
    mkdirSync(join(path, '..'), { recursive: true });
    writeFileSync(path, `#!/bin/sh\nexit ${recent ? 0 : 1}\n`);
    chmodSync(path, 0o755);
}

function findPhp(t, { onPath = [], cpanel = [], env = {} } = {}) {
    const root = mkdtempSync(join(tmpdir(), 'perysee-installsh-'));
    t.after(() => rmSync(root, { recursive: true, force: true }));
    const bin = join(root, 'bin');
    for (const [name, recent] of onPath) {
        fakePhp(join(bin, name), recent);
    }
    for (const [version, recent] of cpanel) {
        fakePhp(join(root, 'cpanel', `ea-php${version}`, 'root/usr/bin/php'), recent);
    }
    const result = spawnSync('/bin/bash', ['-c', `source "$1"; find_php`, 'bash', script], {
        encoding: 'utf8',
        env: { PATH: bin, PERYSEE_INSTALL_SOURCED: '1', PERYSEE_CPANEL_ROOT: join(root, 'cpanel'), ...env },
    });
    return { result, root, bin };
}

test('sourcing the script defines its functions without running the installation', (t) => {
    const { result } = findPhp(t, { onPath: [['php', true]] });
    assert.equal(result.status, 0, result.stderr);
});

test('the default php is used when it is recent enough', (t) => {
    const { result, bin } = findPhp(t, { onPath: [['php', true]], cpanel: [['84', true]] });
    assert.equal(result.stdout.trim(), join(bin, 'php'));
});

test('an old default php is skipped for the newest cPanel php that qualifies', (t) => {
    const { result, root } = findPhp(t, { onPath: [['php', false]], cpanel: [['82', false], ['83', true], ['85', true]] });
    assert.equal(result.stdout.trim(), join(root, 'cpanel', 'ea-php85', 'root/usr/bin/php'));
});

test('versioned php binaries on the path are found when php itself is too old', (t) => {
    const { result, bin } = findPhp(t, { onPath: [['php', false], ['php8.4', true]] });
    assert.equal(result.stdout.trim(), join(bin, 'php8.4'));
});

test('PERYSEE_PHP always wins, even over a recent default', (t) => {
    const { result } = findPhp(t, { onPath: [['php', true]], env: { PERYSEE_PHP: '/custom/php' } });
    assert.equal(result.stdout.trim(), '/custom/php');
});

// detect_mode suggests the mode that fits the machine: uapi means a cPanel account, a working docker compose means Docker.
function detectMode(t, { tools = [], cpanelBin = false, dockerWorks = true }) {
    const root = mkdtempSync(join(tmpdir(), 'perysee-detect-'));
    t.after(() => rmSync(root, { recursive: true, force: true }));
    const bin = join(root, 'bin');
    mkdirSync(bin);
    for (const tool of tools) {
        const path = join(bin, tool);
        writeFileSync(path, tool === 'docker' ? `#!/bin/sh\n${dockerWorks ? 'exit 0' : 'exit 1'}\n` : '#!/bin/sh\nexit 0\n');
        chmodSync(path, 0o755);
    }
    if (cpanelBin) {
        fakePhp(join(root, 'cpanel/bin/uapi'), true);
    }
    return spawnSync('/bin/bash', ['-c', 'source "$1"; detect_mode', 'bash', script], {
        encoding: 'utf8',
        env: { PATH: bin, PERYSEE_INSTALL_SOURCED: '1', PERYSEE_UAPI_PATH: join(root, 'cpanel/bin/uapi') },
    }).stdout.trim();
}

test('a cPanel account is detected by its uapi command, even from outside the PATH', (t) => {
    assert.equal(detectMode(t, { tools: ['uapi', 'docker'] }), 'cpanel');
    assert.equal(detectMode(t, { cpanelBin: true }), 'cpanel');
});

test('Docker is suggested only when docker compose actually works', (t) => {
    assert.equal(detectMode(t, { tools: ['docker'] }), 'docker');
    assert.equal(detectMode(t, { tools: ['docker'], dockerWorks: false }), 'manual');
});

test('the manual mode is the suggestion when nothing else fits', (t) => {
    assert.equal(detectMode(t, {}), 'manual');
});

test('no usable php explains what is needed and fails', (t) => {
    const { result } = findPhp(t, { onPath: [['php', false]] });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /PHP CLI 8\.3 or later/);
});
