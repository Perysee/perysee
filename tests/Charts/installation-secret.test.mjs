import { test } from "node:test";
import assert from "node:assert/strict";
import { takeInstallationSecret } from "../../resources/js/installation-secret.mjs";

function browser(hash, path = "/install", search = "") {
    const replaced = [];
    return {
        location: { hash, pathname: path, search },
        history: { replaceState: (...args) => replaced.push(args) },
        replaced,
    };
}

test("the secret in the fragment is returned and removed from the address", () => {
    const { location, history, replaced } = browser("#secret=abc123DEF");
    assert.equal(takeInstallationSecret(location, history), "abc123DEF");
    assert.deepEqual(replaced, [[null, "", "/install"]]);
});

test("the query string is preserved when the fragment is removed", () => {
    const { location, history, replaced } = browser("#secret=abc", "/install", "?lang=fr");
    takeInstallationSecret(location, history);
    assert.deepEqual(replaced, [[null, "", "/install?lang=fr"]]);
});

test("a page without a secret fragment is left untouched", () => {
    for (const hash of ["", "#", "#other=1", "#secret=", "#secret"]) {
        const { location, history, replaced } = browser(hash);
        assert.equal(takeInstallationSecret(location, history), null);
        assert.deepEqual(replaced, []);
    }
});

test("only unreserved characters are accepted so nothing odd is posted", () => {
    const { location, history } = browser("#secret=abc%20def<script>");
    assert.equal(takeInstallationSecret(location, history), null);
});

test("an oversized secret is rejected", () => {
    const { location, history } = browser("#secret=" + "a".repeat(129));
    assert.equal(takeInstallationSecret(location, history), null);
});
