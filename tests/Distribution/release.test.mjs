import { createHash } from 'node:crypto';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, existsSync, rmSync, chmodSync, readdirSync, statSync } from 'node:fs';
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
        'resources/views/example.blade.php', 'routes/web.php', 'artisan', 'composer.json', 'composer.lock', 'README.md', 'LICENSE', 'LICENSING.md', 'MODULE-EXCEPTION.md',
        'public/index.php', 'public/.htaccess', 'public/site-icon.svg', 'public/logo.svg', 'public/logo-dark.svg', 'public/favicon.svg', 'public/color-mode.js', 'public/fonts/geist.woff2', 'public/tracker/lueur.v1.js',
        'public/brands/chatgpt.svg', 'public/brands/LICENSE', 'public/maps/world.json', 'public/tracker/lueur.v1.js.gz', 'vendor/autoload.php', 'vendor/composer/platform_check.php']) {
        mkdirSync(join(source, path, '..'), { recursive: true });
        writeFileSync(join(source, path), '<?php // fixture');
    }
    writeFileSync(join(source, 'config/perysee.php'), "<?php return ['version' => '1.2.3'];");
    mkdirSync(join(source, 'resources/geoip'), {recursive:true});
    const db = Buffer.from('MMDB fixture');
    writeFileSync(join(source, 'resources/geoip/country.mmdb'), db);
    writeFileSync(join(source, 'resources/geoip/manifest.json'), JSON.stringify({edition:'test',sha256:createHash('sha256').update(db).digest('hex')}));
    writeFileSync(join(source, 'vendor/composer/installed.json'), JSON.stringify({ dev: false, packages: [] }));
    mkdirSync(join(source, 'public/build/assets'), { recursive: true });
    writeFileSync(join(source, 'public/build/manifest.json'), JSON.stringify({ 'resources/js/app.js': { file: 'assets/app.js' } }));
    writeFileSync(join(source, 'public/build/assets/app.js'), 'console.log("production")');
    for (const path of ['.env', '.env.testing', 'bootstrap/cache/config.php', 'storage/app/private/installation/installed',
        'storage/app/private/installation/secret', 'storage/logs/laravel.log', 'storage/framework/sessions/private',
        'public/hot', 'node_modules/private', 'tests/private', 'infra/private', 'DEVELOPMENT.md', 'docs/SUIVI-FONCTIONNALITES.md']) {
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
test('a release whose tag differs from the version in config/perysee.php is refused', t => {
    const f = fixture(t);
    writeFileSync(join(f.source, 'config/perysee.php'), "<?php return ['version' => '1.2.2'];");
    const result = run(join(scripts, 'build-release.php'), ['--source', f.source, '--output', f.output, '--version', 'v1.2.3', '--repository', 'example/perysee']);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr + result.stdout, /declares version 1\.2\.2 but the release is v1\.2\.3/);
    assert.equal(existsSync(join(f.output, 'perysee.zip')), false);
});
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
    for (const path of ['resources/geoip/country.mmdb', 'resources/geoip/manifest.json', 'public/brands/chatgpt.svg', 'public/maps/world.json', 'README.md', 'LICENSE', 'LICENSING.md', 'MODULE-EXCEPTION.md', 'vendor/autoload.php', 'public/build/manifest.json', 'public/.htaccess', 'public/site-icon.svg', 'public/logo.svg', 'public/logo-dark.svg', 'public/favicon.svg', 'public/color-mode.js', 'public/tracker/lueur.v1.js.gz',
        'storage/framework/sessions', 'storage/framework/cache/data', 'storage/framework/cache/locks', 'storage/framework/views', 'bootstrap/cache']) {
        assert.ok(existsSync(join(target, path)), path);
    }
    for (const path of ['.env', '.env.testing', 'bootstrap/cache/config.php', 'storage/app/private/installation/installed',
        'storage/app/private/installation/secret', 'storage/logs/laravel.log', 'public/hot', 'node_modules', 'tests', 'infra', 'DEVELOPMENT.md', 'docs/SUIVI-FONCTIONNALITES.md']) {
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

function bootstrapEnvironment(f) {
    const bin = join(f.root, 'bin');
    mkdirSync(bin);
    const curl = join(bin, 'curl');
    writeFileSync(curl, `#!/usr/bin/env node
import { copyFileSync, appendFileSync } from 'node:fs';
import { basename, join } from 'node:path';
const args = process.argv.slice(2);
const url = args.find(arg => arg.startsWith('https://'));
if (!url || process.env.PERYSEE_TEST_DOWNLOAD_FAIL) process.exit(22);
if (process.env.PERYSEE_TEST_URL_LOG) appendFileSync(process.env.PERYSEE_TEST_URL_LOG, url + '\\n');
const output = args[args.indexOf('--output') + 1];
copyFileSync(join(process.env.PERYSEE_TEST_RELEASE, basename(new URL(url).pathname)), output);
`);
    chmodSync(curl, 0o755);
    return { ...process.env, PATH: `${bin}:${process.env.PATH}`, TMPDIR: f.root,
        PERYSEE_TEST_RELEASE: f.output, PERYSEE_DOWNLOAD_URL: 'https://downloads.example.com/latest' };
}
test('one-line shell bootstrap downloads, verifies and installs the release without build tools', (t) => {
    const f = fixture(t);
    build(f);
    const target = join(f.root, 'from bootstrap');
    const result = spawnSync('bash', [join(scripts, 'install.sh'), '--target', target, '--url', 'https://stats.example.com', '--yes'],
        { encoding: 'utf8', env: bootstrapEnvironment(f) });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.ok(existsSync(join(target, 'public/build/manifest.json')));
    assert.match(result.stdout, /https:\/\/stats.example.com\/install/);
    assert.equal(readdirSync(f.root).some(name => name.startsWith('perysee-bootstrap.')), false);
    assert.ok(existsSync(join(f.output, 'install.sh')));
    assert.match(readFileSync(join(f.output, 'SHA256SUMS'), 'utf8'), /  install\.sh\n/);
});
test('the published shell installer downloads its pinned version through cPanel without client GitHub credentials', t => {
    const f = fixture(t);
    build(f);
    const env = bootstrapEnvironment(f);
    delete env.PERYSEE_DOWNLOAD_URL;
    delete env.GH_TOKEN;
    delete env.GITHUB_TOKEN;
    env.PERYSEE_TEST_URL_LOG = join(f.root, 'urls.log');
    const result = spawnSync('bash', [join(f.output, 'install.sh'), '--target', join(f.root, 'public install'), '--url', 'https://stats.example.com', '--yes'], { encoding: 'utf8', env });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    const urls = readFileSync(env.PERYSEE_TEST_URL_LOG, 'utf8').trim().split('\n');
    assert.equal(urls.length, 3);
    for (const url of urls) assert.ok(url.startsWith('https://get.perysee.com/releases/v1.2.3/'), url);
});

test('shell bootstrap never executes an installer that fails checksum verification', (t) => {
    const f = fixture(t);
    build(f);
    const marker = join(f.root, 'executed');
    writeFileSync(join(f.output, 'install.php'), `<?php file_put_contents(${JSON.stringify(marker)}, 'bad');`);
    const result = spawnSync('bash', [join(scripts, 'install.sh'), '--target', join(f.root, 'target'), '--url', 'https://stats.example.com', '--yes'],
        { encoding: 'utf8', env: bootstrapEnvironment(f) });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /checksum/i);
    assert.equal(existsSync(marker), false);
    assert.equal(existsSync(join(f.root, 'target')), false);
    assert.equal(readdirSync(f.root).some(name => name.startsWith('perysee-bootstrap.')), false);
});
test('failed bootstrap download leaves the destination untouched and removes temporary files', (t) => {
    const f = fixture(t);
    build(f);
    const result = spawnSync('bash', [join(scripts, 'install.sh'), '--target', join(f.root, 'target'), '--url', 'https://stats.example.com', '--yes'],
        { encoding: 'utf8', env: { ...bootstrapEnvironment(f), PERYSEE_TEST_DOWNLOAD_FAIL: '1' } });
    assert.notEqual(result.status, 0);
    assert.equal(result.status, 22);
    assert.equal(existsSync(join(f.root, 'target')), false);
    assert.equal(readdirSync(f.root).some(name => name.startsWith('perysee-bootstrap.')), false);
});

test('curl-piped shell bootstrap keeps interactive questions on the controlling terminal', (t) => {
    const f = fixture(t);
    build(f);
    const target = join(f.root, 'interactive pipeline');
    const python = `import os, pty, signal, sys
signal.alarm(20)
pid, master = pty.fork()
if pid == 0:
    os.execv('/bin/bash', ['bash', '-c', 'cat "$PERYSEE_TEST_BOOTSTRAP" | bash'])
os.write(master, ('3\\n' + os.environ['PERYSEE_TEST_TARGET'] + '\\nhttps://stats.example.com\\nyes\\n').encode())
while True:
    try:
        data = os.read(master, 65536)
        if not data: break
        sys.stdout.buffer.write(data)
        sys.stdout.buffer.flush()
    except OSError:
        break
_, status = os.waitpid(pid, 0)
sys.exit(os.waitstatus_to_exitcode(status))
`;
    const result = spawnSync('python3', ['-c', python], { encoding: 'utf8', timeout: 25000,
        env: { ...bootstrapEnvironment(f), PERYSEE_TEST_BOOTSTRAP: join(scripts, 'install.sh'), PERYSEE_TEST_TARGET: target } });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.ok(existsSync(join(target, 'artisan')));
    assert.match(result.stdout, /How do you want to install Perysee\?/);
    assert.match(result.stdout, /Application directory/);
    assert.match(result.stdout, /https:\/\/stats.example.com\/install/);
});
test('bundled country database must match its pinned checksum', t => {
    const f = fixture(t);
    writeFileSync(join(f.source, 'resources/geoip/country.mmdb'), 'tampered database');
    const result = run(join(scripts, 'build-release.php'), ['--source', f.source, '--output', f.output, '--version', 'v1.2.3', '--repository', 'example/perysee']);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /country database checksum mismatch/);
});

// A stand-in for the application's own artisan, which reports how install.php called it.
function reportingArtisan(f, { status = 0 } = {}) {
    writeFileSync(join(f.source, 'artisan'), `<?php
echo 'ARTISAN ' . implode(' ', array_slice($argv, 1)) . "\\n";
if (in_array('--browser', $argv, true)) {
    echo "https://stats.example.com/install#secret=fixture-secret\\n";
}
exit(${status});
`);
}
test('an unknown mode is refused before anything is downloaded or written', (t) => {
    const f = fixture(t);
    build(f);
    const target = join(f.root, 'app');
    const result = install(f, target, ['--mode', 'nonsense']);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Unknown installation mode/);
    assert.equal(existsSync(target), false);
});
test('the manual mode prepares the browser installer and prints the link that carries the secret', (t) => {
    const f = fixture(t);
    reportingArtisan(f);
    build(f);
    const target = join(f.root, 'app');
    const result = install(f, target, ['--mode', 'manual']);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /ARTISAN perysee:install --browser --url=https:\/\/stats\.example\.com/);
    assert.match(result.stdout, /https:\/\/stats\.example\.com\/install#secret=fixture-secret/);
    assert.match(result.stdout, /document root to: .*\/app\/public/);
});
test('finishing in the terminal hands over to perysee:install and reports its failure', (t) => {
    const f = fixture(t);
    reportingArtisan(f, { status: 1 });
    build(f);
    const result = install(f, join(f.root, 'app'), ['--finish', 'terminal']);
    assert.notEqual(result.status, 0);
    assert.match(result.stdout, /ARTISAN perysee:install --url=https:\/\/stats\.example\.com/);
    assert.doesNotMatch(result.stdout, /--browser/);
    assert.match(result.stderr, /perysee:install did not finish/);
});
test('finish none installs the files only and keeps the manual instructions', (t) => {
    const f = fixture(t);
    reportingArtisan(f);
    build(f);
    const target = join(f.root, 'app');
    const result = install(f, target, ['--finish', 'none']);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.doesNotMatch(result.stdout, /ARTISAN/);
    assert.match(result.stdout, /https:\/\/stats\.example\.com\/install/);
    assert.equal(existsSync(join(target, '.env')), false);
});
test('a failing browser preparation falls back to the manual instructions without failing the install', (t) => {
    const f = fixture(t);
    reportingArtisan(f, { status: 1 });
    build(f);
    const result = install(f, join(f.root, 'app'));
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /storage\/app\/private\/installation\/secret/);
});
test('an unknown finish value is refused', (t) => {
    const f = fixture(t);
    build(f);
    const result = install(f, join(f.root, 'app'), ['--finish', 'whatever']);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /--finish must be/);
});
function installCpanel(f, target, extra = []) {
    return run(join(f.output, 'install.php'), ['--archive', join(f.output, 'perysee.zip'),
        '--checksums', join(f.output, 'SHA256SUMS'), '--mode', 'cpanel', '--target', target, '--yes', ...extra]);
}
test('the cPanel mode hands over to perysee:install --cpanel with the hostname and derives the address from it', (t) => {
    const f = fixture(t);
    reportingArtisan(f);
    build(f);
    const target = join(f.root, 'app');
    const result = installCpanel(f, target, ['--domain', 'stats.example.com']);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /ARTISAN perysee:install --cpanel --domain=stats\.example\.com/);
    assert.doesNotMatch(result.stdout, /--browser/);
    assert.ok(existsSync(join(target, 'artisan')));
});
test('the cPanel mode needs a hostname and cannot finish in the browser', (t) => {
    const f = fixture(t);
    reportingArtisan(f);
    build(f);
    const missing = installCpanel(f, join(f.root, 'one'));
    assert.notEqual(missing.status, 0);
    assert.match(missing.stderr, /--domain/);
    const browser = installCpanel(f, join(f.root, 'two'), ['--domain', 'stats.example.com', '--finish', 'browser']);
    assert.notEqual(browser.status, 0);
    assert.match(browser.stderr, /cPanel mode finishes in the terminal/);
    assert.equal(existsSync(join(f.root, 'one')), false);
    assert.equal(existsSync(join(f.root, 'two')), false);
});
test('a hostname that is not a plain hostname is refused before anything is written', (t) => {
    const f = fixture(t);
    build(f);
    const result = installCpanel(f, join(f.root, 'app'), ['--domain', 'https://stats.example.com/x']);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /hostname/);
    assert.equal(existsSync(join(f.root, 'app')), false);
});
test('the release publishes the Docker files under the same checksum list as the ZIP', (t) => {
    const f = fixture(t);
    build(f);
    const sums = readFileSync(join(f.output, 'SHA256SUMS'), 'utf8');
    for (const name of ['Dockerfile', 'compose.yaml', 'docker-entrypoint.sh']) {
        assert.ok(existsSync(join(f.output, name)), name);
        const hash = createHash('sha256').update(readFileSync(join(f.output, name))).digest('hex');
        assert.ok(sums.includes(`${hash}  ${name}\n`), name);
    }
});
// docker is faked: it records every call, and `compose version` succeeds.
// The fake docker comes first in PATH, so a docker installed on the machine running the tests is never reached.
// With PERYSEE_TEST_DOCKER_BROKEN set it behaves like a docker without the compose plugin.
function addDocker(f) {
    const docker = join(f.root, 'bin', 'docker');
    writeFileSync(docker, `#!/usr/bin/env node
import { appendFileSync } from 'node:fs';
const args = process.argv.slice(2);
if (process.env.PERYSEE_TEST_DOCKER_BROKEN && args[0] === 'compose' && args[1] === 'version') process.exit(1);
appendFileSync(process.env.PERYSEE_TEST_DOCKER_LOG, JSON.stringify(args) + '\\n');
`);
    chmodSync(docker, 0o755);
}
function dockerEnvironment(f, { withDocker = true } = {}) {
    const env = bootstrapEnvironment(f);
    env.PERYSEE_TEST_DOCKER_LOG = join(f.root, 'docker.log');
    env.PERYSEE_PHP = '/nonexistent/php'; // the Docker mode must not need PHP on the host
    addDocker(f);
    if (!withDocker) {
        env.PERYSEE_TEST_DOCKER_BROKEN = '1';
    }
    return env;
}
function dockerInstall(f, env, extra = []) {
    return spawnSync('bash', [join(scripts, 'install.sh'), '--mode', 'docker', '--url', 'https://stats.example.com', '--admin-email', 'owner@example.com', '--yes', ...extra],
        { encoding: 'utf8', env, input: '' });
}
function dockerCalls(f) {
    const log = join(f.root, 'docker.log');
    return existsSync(log) ? readFileSync(log, 'utf8').trim().split('\n').filter(Boolean).map((line) => JSON.parse(line)) : [];
}
test('the Docker mode verifies the release, writes the project and secrets, builds and installs without PHP', (t) => {
    const f = fixture(t);
    build(f);
    const target = join(f.root, 'perysee-docker');
    const result = dockerInstall(f, dockerEnvironment(f), ['--target', target, '--port', '8090']);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    for (const name of ['Dockerfile', 'compose.yaml', 'docker-entrypoint.sh', 'perysee.zip', '.env']) {
        assert.ok(existsSync(join(target, name)), name);
    }
    assert.equal(statSync(join(target, '.env')).mode & 0o777, 0o600);
    const env = readFileSync(join(target, '.env'), 'utf8');
    assert.match(env, /^APP_URL=https:\/\/stats\.example\.com$/m);
    assert.match(env, /^APP_KEY=base64:[A-Za-z0-9+/]{43}=$/m);
    assert.match(env, /^DB_PASSWORD=[a-f0-9]{48}$/m);
    assert.match(env, /^MARIADB_ROOT_PASSWORD=[a-f0-9]{48}$/m);
    assert.match(env, /^PERYSEE_PORT=8090$/m);
    assert.doesNotMatch(env, /ADMIN/);
    const calls = dockerCalls(f).map((call) => call.join(' '));
    assert.ok(calls.includes('compose version'));
    assert.ok(calls.includes(`compose --project-directory ${target} up -d --build --wait`), calls.join('\n'));
    const exec = calls.find((call) => call.includes(' exec '));
    assert.match(exec, /exec -T --user www-data app php artisan perysee:install --url=https:\/\/stats\.example\.com --db-host=mariadb --db-name=perysee --db-user=perysee --admin-name=Administrator --admin-email=owner@example\.com --no-interaction/);
    assert.doesNotMatch(result.stdout, /[a-f0-9]{48}/);
});
test('the Docker mode refuses a missing docker, an unverified file and a used folder before writing anything', (t) => {
    const f = fixture(t);
    build(f);
    const target = join(f.root, 'perysee-docker');
    const env = dockerEnvironment(f, { withDocker: false });
    const missing = dockerInstall(f, env, ['--target', target]);
    assert.notEqual(missing.status, 0);
    assert.match(missing.stderr, /Docker with the compose plugin/);
    assert.equal(existsSync(target), false);

    delete env.PERYSEE_TEST_DOCKER_BROKEN;
    writeFileSync(join(f.output, 'Dockerfile'), 'FROM scratch\n');
    const tampered = dockerInstall(f, env, ['--target', target]);
    assert.notEqual(tampered.status, 0);
    assert.match(tampered.stderr, /checksum verification failed/);
    assert.equal(existsSync(target), false);
    assert.deepEqual(dockerCalls(f).filter((call) => call.includes('up')), []);
});
test('the Docker mode never overwrites a used folder and requires an HTTPS address', (t) => {
    const f = fixture(t);
    build(f);
    const env = dockerEnvironment(f);
    const used = join(f.root, 'used');
    mkdirSync(used);
    writeFileSync(join(used, 'file'), 'mine');
    const busy = dockerInstall(f, env, ['--target', used]);
    assert.notEqual(busy.status, 0);
    assert.match(busy.stderr, /not empty/);
    assert.equal(readFileSync(join(used, 'file'), 'utf8'), 'mine');
    const plain = spawnSync('bash', [join(scripts, 'install.sh'), '--mode', 'docker', '--url', 'http://stats.example.com', '--target', join(f.root, 'x'), '--yes'], { encoding: 'utf8', env, input: '' });
    assert.notEqual(plain.status, 0);
    assert.match(plain.stderr, /HTTPS/);
    assert.deepEqual(dockerCalls(f).filter((call) => call.includes('up')), []);
});
test('without --mode and with a terminal, a menu chooses the mode and the machine decides the default', (t) => {
    const f = fixture(t);
    build(f);
    const env = dockerEnvironment(f);
    const answers = join(f.root, 'answers');
    env.PERYSEE_TTY = answers;
    const start = (name) => spawnSync('bash', [join(scripts, 'install.sh'), '--target', join(f.root, name)], { encoding: 'utf8', env, input: '' });

    writeFileSync(answers, '2\nhttps://stats.example.com\nowner@example.com\nyes\n');
    const chosen = start('chosen');
    assert.equal(chosen.status, 0, chosen.stderr || chosen.stdout);
    assert.match(chosen.stdout, /How do you want to install Perysee\?/);
    assert.ok(existsSync(join(f.root, 'chosen/compose.yaml')));

    writeFileSync(answers, '\nhttps://stats.example.com\nowner@example.com\nyes\n');
    const suggested = start('suggested');
    assert.equal(suggested.status, 0, suggested.stderr || suggested.stdout);
    assert.ok(existsSync(join(f.root, 'suggested/compose.yaml')), 'docker is detected and suggested');
});
test('an unknown menu choice stops before anything is downloaded or written', (t) => {
    const f = fixture(t);
    build(f);
    const env = dockerEnvironment(f);
    env.PERYSEE_TTY = join(f.root, 'answers');
    writeFileSync(env.PERYSEE_TTY, '9\n');
    const target = join(f.root, 'nothing');
    const result = spawnSync('bash', [join(scripts, 'install.sh'), '--target', target], { encoding: 'utf8', env, input: '' });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Unknown choice/);
    assert.equal(existsSync(target), false);
    assert.deepEqual(dockerCalls(f).filter((call) => call.includes('up')), []);
});
test('the Docker mode asks for what is missing, keeps the password prompt interactive and can be cancelled', (t) => {
    const f = fixture(t);
    build(f);
    const env = dockerEnvironment(f);
    const answers = join(f.root, 'answers');
    const target = join(f.root, 'perysee-docker');
    env.PERYSEE_TTY = answers;
    writeFileSync(answers, 'no\n');
    const run = () => spawnSync('bash', [join(scripts, 'install.sh'), '--mode', 'docker', '--target', target], { encoding: 'utf8', env, input: '' });

    writeFileSync(answers, 'https://stats.example.com\nowner@example.com\nno\n');
    const cancelled = run();
    assert.equal(cancelled.status, 0, cancelled.stderr);
    assert.match(cancelled.stdout, /Installation cancelled/);
    assert.equal(existsSync(target), false);

    writeFileSync(answers, 'https://stats.example.com\nowner@example.com\nyes\n');
    const result = run();
    assert.equal(result.status, 0, result.stderr || result.stdout);
    const exec = dockerCalls(f).map((call) => call.join(' ')).find((call) => call.includes(' exec '));
    assert.ok(exec, JSON.stringify({ stdout: result.stdout, stderr: result.stderr, calls: dockerCalls(f) }));
    assert.doesNotMatch(exec, / -T /);
    assert.doesNotMatch(exec, /--no-interaction/);
    assert.match(exec, /--admin-email=owner@example\.com/);
    assert.match(readFileSync(join(target, '.env'), 'utf8'), /^APP_URL=https:\/\/stats\.example\.com$/m);
});
test('upgrading a Docker installation replaces the release files, keeps the secrets and rebuilds', (t) => {
    const f = fixture(t);
    build(f);
    const env = dockerEnvironment(f);
    const target = join(f.root, 'perysee-docker');
    assert.equal(dockerInstall(f, env, ['--target', target]).status, 0);
    const secrets = readFileSync(join(target, '.env'), 'utf8');
    writeFileSync(join(target, 'Dockerfile'), 'old Dockerfile');
    writeFileSync(env.PERYSEE_TEST_DOCKER_LOG, '');

    const result = spawnSync('bash', [join(scripts, 'install.sh'), '--mode', 'docker', '--upgrade', '--target', target, '--yes'], { encoding: 'utf8', env, input: '' });

    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.equal(readFileSync(join(target, '.env'), 'utf8'), secrets);
    assert.notEqual(readFileSync(join(target, 'Dockerfile'), 'utf8'), 'old Dockerfile');
    const calls = dockerCalls(f).map((call) => call.join(' '));
    assert.ok(calls.includes(`compose --project-directory ${target} build --pull`), calls.join('\n'));
    assert.ok(calls.includes(`compose --project-directory ${target} up -d --wait`), calls.join('\n'));
    assert.equal(calls.some((call) => call.includes('perysee:install')), false);
});
test('upgrading needs an existing Docker installation', (t) => {
    const f = fixture(t);
    build(f);
    const env = dockerEnvironment(f);
    const empty = join(f.root, 'nothing');
    mkdirSync(empty);
    const result = spawnSync('bash', [join(scripts, 'install.sh'), '--mode', 'docker', '--upgrade', '--target', empty, '--yes'], { encoding: 'utf8', env, input: '' });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /No Docker installation found/);
    assert.deepEqual(readdirSync(empty), []);
});
test('the cPanel mode reports a failed perysee:install and exits with an error', (t) => {
    const f = fixture(t);
    reportingArtisan(f, { status: 1 });
    build(f);
    const result = installCpanel(f, join(f.root, 'app'), ['--domain', 'stats.example.com']);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /perysee:install did not finish/);
});
