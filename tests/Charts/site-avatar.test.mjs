import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initials, siteAvatar } from '../../resources/js/site-avatar.mjs';

test('initials use the first letter of the first two words and ignore punctuation', () => {
    assert.equal(initials('Demo · No identifier'), 'DN');
    assert.equal(initials('racinesapp.com'), 'RC');
    assert.equal(initials('Perysee'), 'P');
    assert.equal(initials(''), '');
    assert.equal(initials(null), '');
});
test('the icon url asks for no content when the site has no favicon', () => {
    assert.equal(siteAvatar('/sites/4/favicon', 'Perysee').src, '/sites/4/favicon?initials=1');
    assert.equal(siteAvatar('/sites/4/favicon?v=1', 'Perysee').src, '/sites/4/favicon?v=1&initials=1');
    assert.equal(siteAvatar(undefined, 'Perysee').src, undefined);
});
