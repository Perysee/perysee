import { test } from "node:test";
import assert from "node:assert/strict";
import { describeEntry, groupSecret, networkLabel, recoveryCodesText } from "../../resources/js/security.mjs";

test("an entry is described from its event and context only", () => {
    assert.equal(describeEntry({ event: "login.success", context: { method: "totp" } }), "Signed in with an authenticator code");
    assert.equal(describeEntry({ event: "login.success", context: { method: "password" } }), "Signed in with a password");
    assert.equal(describeEntry({ event: "login.failed", context: { stage: "two_factor" } }), "Failed sign-in: wrong code");
    assert.equal(describeEntry({ event: "login.failed", context: { stage: "password" } }), "Failed sign-in: wrong password");
    assert.equal(describeEntry({ event: "twofactor.disabled", context: { actor: "cli" } }), "Two-factor authentication turned off from the command line");
    assert.equal(describeEntry({ event: "session.revoked", context: { count: 2 } }), "Other sessions revoked (2)");
    assert.equal(describeEntry({ event: "session.revoked", context: { device: "Firefox on Linux" } }), "Session revoked: Firefox on Linux");
    assert.equal(describeEntry({ event: "share.created", context: null }), "Share link created");
});

test("an event this version does not know is shown by its name rather than hidden", () => {
    assert.equal(describeEntry({ event: "something.new" }), "something.new");
});

test("the truncated address is shown as the network it stands for", () => {
    assert.equal(networkLabel("203.0.113.0"), "203.0.113.x");
    assert.equal(networkLabel("2001:db8:abcd::"), "2001:db8:abcd::/48");
    assert.equal(networkLabel(null), "Unknown network");
});

test("the key is grouped by four and a grouped key is left alone", () => {
    assert.equal(groupSecret("ABCDEFGHIJKLMNOP"), "ABCD EFGH IJKL MNOP");
    assert.equal(groupSecret("ABCD EFGH"), "ABCD EFGH");
});

test("the recovery codes download lists every code once on its own line", () => {
    const text = recoveryCodesText(["AAAAA-BBBBB", "CCCCC-DDDDD"]);
    assert.match(text, /^Perysee recovery codes\n/);
    assert.deepEqual(text.split("\n").filter((line) => /^[A-Z]{5}-[A-Z]{5}$/.test(line)), ["AAAAA-BBBBB", "CCCCC-DDDDD"]);
});
