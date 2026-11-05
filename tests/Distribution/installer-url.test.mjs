import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// get.perysee.com itself (the redirections) is maintained and tested in Perysee/web, infra/get.
test('the installer built by a release downloads from the versioned redirect', () => {
    const builder = readFileSync(resolve('scripts/build-release.php'), 'utf8');
    assert.match(builder, /'https:\/\/get\.perysee\.com\/releases\/'\.\$version/);
});
