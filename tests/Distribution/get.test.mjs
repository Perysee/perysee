import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const releases = 'https://github.com/Perysee/app/releases';
// Rules that follow a RewriteCond only apply to HTTP or non-GET requests; the checks below cover them.
const rules = [];
let conditional = false;
for (const line of readFileSync(resolve('infra/get/.htaccess'), 'utf8').split('\n').map((entry) => entry.trim())) {
    if (line.startsWith('RewriteCond ')) {
        conditional = true;
    } else if (line.startsWith('RewriteRule ')) {
        if (!conditional) {
            rules.push(line);
        }
        conditional = false;
    }
}

/** Evaluates the RewriteRule lines in order, the way Apache does for a request path without a leading slash. */
function resolveRequest(path) {
    for (const line of rules) {
        const [, pattern, substitution, flags = ''] = line.match(/^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[(.+)\])?$/);
        const match = new RegExp(pattern).exec(path);
        if (!match) {
            continue;
        }
        const code = /R=(\d+)/.exec(flags)?.[1];
        const target = substitution.replace(/\$(\d)/g, (_, index) => match[Number(index)] ?? '');
        if (substitution === '-' && !code) {
            return { status: 200 };
        }

        return code && Number(code) < 400 ? { status: Number(code), location: target } : { status: Number(code ?? 200) };
    }

    return { status: 200 };
}

test('the bare domain serves the latest installer', () => {
    assert.deepEqual(resolveRequest(''), { status: 302, location: `${releases}/latest/download/install.sh` });
});

for (const name of ['perysee.zip', 'install.php', 'install.sh', 'SHA256SUMS']) {
    test(`${name} redirects to the latest release`, () => {
        assert.deepEqual(resolveRequest(name), { status: 302, location: `${releases}/latest/download/${name}` });
    });

    test(`${name} redirects to its exact tag`, () => {
        assert.deepEqual(resolveRequest(`releases/v1.2.3/${name}`), { status: 302, location: `${releases}/download/v1.2.3/${name}` });
        assert.deepEqual(resolveRequest(`releases/v0.2.0-beta.1/${name}`), { status: 302, location: `${releases}/download/v0.2.0-beta.1/${name}` });
    });
}

test('anything else is a 404', () => {
    for (const path of ['anything', 'releases/v1.2.3/secret.txt', 'releases/latest/perysee.zip', 'releases/v1.2.3/perysee.zip/extra', 'releases/v1.2/perysee.zip', 'releases/v1.2.3/../install.sh', 'perysee.zip.bak', 'install.php/x']) {
        assert.equal(resolveRequest(path).status, 404, path);
    }
});

test('the ACME challenge of AutoSSL stays reachable', () => {
    assert.equal(resolveRequest('.well-known/acme-challenge/token').status, 200);
});

test('only GET and HEAD are accepted, over HTTPS, without directory listings', () => {
    const file = readFileSync(resolve('infra/get/.htaccess'), 'utf8');
    assert.match(file, /RewriteCond %\{REQUEST_METHOD\} !\^\(GET\|HEAD\)\$\s+RewriteRule \^ - \[R=405,L\]/);
    assert.match(file, /RewriteCond %\{HTTPS\} !=on[\s\S]*RewriteRule \^ https:\/\/%\{HTTP_HOST\}%\{REQUEST_URI\} \[L,R=301\]/);
    assert.match(file, /^Options -Indexes -MultiViews$/m);
});

test('the installer built by a release downloads from the versioned redirect', () => {
    const builder = readFileSync(resolve('scripts/build-release.php'), 'utf8');
    assert.match(builder, /'https:\/\/get\.perysee\.com\/releases\/'\.\$version/);
});
