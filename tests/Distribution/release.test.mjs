import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const php = process.env.PHP_BINARY || 'php';
const scripts = resolve('scripts');
function run(file, args = [], input) {
    return spawnSync(php, [file, ...args], { encoding: 'utf8', input });
}
function fixture(t) {
    const root = mkdtempSync(join(tmpdir(), 'perysee-release-test-'));
    t.after(() => rmSync(root, { recursive: true, force: true }));
    const source = join(root, 'source');
    const output = join(root, 'output');
    for (const path of ['app/Example.php', 'bootstrap/app.php', 'config/app.php', 'database/migrations/example.php',
        'resources/views/example.blade.php', 'routes/web.php', 'artisan', 'composer.json', 'composer.lock', 'README.md', 'docs/INSTALLATION-SUIVI.md', 'docs/CONFIDENTIALITE.md',
        'public/index.php', 'public/.htaccess', 'public/fonts/geist.woff2', 'public/tracker/lueur.v1.js',
        'public/tracker/lueur.v1.js.gz', 'vendor/autoload.php', 'vendor/composer/platform_check.php']) {
        mkdirSync(join(source, path, '..'), { recursive: true });
        writeFileSync(join(source, path), '<?php // fixture');
    }
    writeFileSync(join(source, 'vendor/composer/installed.json'), JSON.stringify({ dev: false, packages: [] }));
    mkdirSync(join(source, 'public/build/assets'), { recursive: true });
    writeFileSync(join(source, 'public/build/manifest.json'), JSON.stringify({ 'resources/js/app.js': { file: 'assets/app.js' } }));
    writeFileSync(join(source, 'public/build/assets/app.js'), 'console.log("production")');
    for (const path of ['.env', '.env.testing', 'bootstrap/cache/config.php', 'storage/app/private/installation/installed',
        'storage/app/private/installation/secret', 'storage/logs/laravel.log', 'storage/framework/sessions/private',
        'public/hot', 'node_modules/private', 'tests/private', 'infra/private']) {
        mkdirSync(join(source, path, '..'), { recursive: true });
        writeFileSync(join(source, path), 'DO_NOT_SHIP');
    }
    return { root, source, output };
}
function build(f) {
    const result = run(join(scripts, 'build-release.php'), ['--source', f.source, '--output', f.output,
        '--version', 'v1.2.3', '--repository', 'example/perysee']);
    assert.equal(result.status, 0, result.stderr || result.stdout);
}
function install(f, target, extra = []) {
    return run(join(f.output, 'install.php'), ['--archive', join(f.output, 'perysee.zip'),
        '--checksums', join(f.output, 'SHA256SUMS'), '--target', target,
        '--url', 'https://stats.example.com', '--yes', ...extra]);
}
test('production ZIP installs offline without exposing local secrets or generated caches', (t) => {
    const f = fixture(t);
    build(f);
    const target = join(f.root, 'chosen folder');
    const result = install(f, target);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /chosen folder\/public/);
    assert.match(result.stdout, /https:\/\/stats.example.com\/install/);
    for (const path of ['README.md', 'docs/INSTALLATION-SUIVI.md', 'docs/CONFIDENTIALITE.md', 'vendor/autoload.php', 'public/build/manifest.json', 'public/.htaccess', 'public/tracker/lueur.v1.js.gz',
        'storage/framework/sessions', 'storage/framework/cache/data', 'storage/framework/cache/locks', 'storage/framework/views', 'bootstrap/cache']) {
        assert.ok(existsSync(join(target, path)), path);
    }
    for (const path of ['.env', '.env.testing', 'bootstrap/cache/config.php', 'storage/app/private/installation/installed',
        'storage/app/private/installation/secret', 'storage/logs/laravel.log', 'public/hot', 'node_modules', 'tests', 'infra']) {
        assert.equal(existsSync(join(target, path)), false, path);
    }
    assert.deepEqual(JSON.parse(readFileSync(join(target, 'release.json'))), { version: 'v1.2.3', repository: 'example/perysee' });
    assert.match(readFileSync(join(f.output, 'install.php'), 'utf8'), /example\/perysee/);
});
test('existing installation, including a directory with only hidden files, is never overwritten', (t) => {
    const f = fixture(t);
    build(f);
    const target = join(f.root, 'installed');
    mkdirSync(target);
    writeFileSync(join(target, '.env'), 'ORIGINAL_KEY');
    const result = install(f, target);
    assert.notEqual(result.status, 0);
    assert.equal(readFileSync(join(target, '.env'), 'utf8'), 'ORIGINAL_KEY');
    assert.equal(existsSync(join(target, 'artisan')), false);
});
test('checksum mismatch fails before creating the destination', (t) => {
    const f = fixture(t);
    build(f);
    writeFileSync(join(f.output, 'SHA256SUMS'), `${'0'.repeat(64)}  perysee.zip\n`);
    const target = join(f.root, 'rejected');
    const result = install(f, target);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /checksum/i);
    assert.equal(existsSync(target), false);
});
test('packaging rejects development dependencies and missing compiled assets', (t) => {
    const f = fixture(t);
    writeFileSync(join(f.source, 'vendor/composer/installed.json'), JSON.stringify({ dev: true, packages: [] }));
    let result = run(join(scripts, 'build-release.php'), ['--source', f.source, '--output', f.output, '--version', 'v1.2.3', '--repository', 'example/perysee']);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /production|no-dev/i);
    writeFileSync(join(f.source, 'vendor/composer/installed.json'), JSON.stringify({ dev: false, packages: [] }));
    rmSync(join(f.source, 'public/build/assets/app.js'));
    result = run(join(scripts, 'build-release.php'), ['--source', f.source, '--output', f.output, '--version', 'v1.2.3', '--repository', 'example/perysee']);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /asset/i);
});
test('interactive install uses the selected directory and prints the browser handoff', (t) => {
    const f = fixture(t);
    build(f);
    const target = join(f.root, 'interactive');
    const result = run(join(f.output, 'install.php'), ['--archive', join(f.output, 'perysee.zip'), '--checksums', join(f.output, 'SHA256SUMS')],
        `${target}\nhttps://stats.example.com\nyes\n`);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.ok(existsSync(join(target, 'artisan')));
    assert.match(result.stdout, /https:\/\/stats.example.com\/install/);
});
test('archive traversal and symlinks are rejected without writing outside staging', (t) => {
    for (const symlink of [false, true]) {
        const f = fixture(t);
        build(f);
        const payload = symlink ? 'perysee/escape' : 'perysee/../../escape';
        const code = '$z=new ZipArchive; $z->open($argv[1], ZipArchive::CREATE|ZipArchive::OVERWRITE); $z->addFromString($argv[2], "outside"); if($argv[3]==="link"){$z->setExternalAttributesName($argv[2], ZipArchive::OPSYS_UNIX, 0120777 << 16);} $z->close(); file_put_contents($argv[4], hash_file("sha256", $argv[1])."  perysee.zip\\n");';
        const archive = join(f.output, 'perysee.zip');
        const changed = run('-r', [code, archive, payload, symlink ? 'link' : 'file', join(f.output, 'SHA256SUMS')]);
        assert.equal(changed.status, 0, changed.stderr);
        const target = join(f.root, 'rejected');
        const result = install(f, target);
        assert.notEqual(result.status, 0);
        assert.match(result.stderr, /unsafe|symlink/i);
        assert.equal(existsSync(target), false);
        assert.equal(existsSync(join(f.root, 'escape')), false);
    }
});
