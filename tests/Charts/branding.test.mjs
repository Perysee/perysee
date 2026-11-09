import { test } from "node:test";
import assert from "node:assert/strict";
import { accentCss, pageTitle } from "../../resources/js/branding.mjs";

test("no accent means no override, so the Perysee theme stays untouched", () => {
    assert.equal(accentCss(null), "");
    assert.equal(accentCss(undefined), "");
});

test("an accent overrides the primary and action colors in both color modes", () => {
    const css = accentCss("#12ab34");

    assert.match(css, /:root\{[^}]*--ui-primary:#12ab34;/);
    assert.match(css, /:root\{[^}]*--brand-action:#12ab34;/);
    assert.match(css, /\.dark\{[^}]*--ui-primary:color-mix\(in srgb, #12ab34 55%, #FFFFFF\);/);
    assert.match(css, /\.dark\{[^}]*--brand-action:#12ab34;/);
});

test("dark surfaces stay neutral whatever the accent, so a white label never tints the backgrounds", () => {
    const dark = accentCss("#12ab34").match(/\.dark\{[^}]*\}/)[0];

    assert.match(dark, /--brand-subtle:#26262B;/);
    assert.doesNotMatch(dark, /--brand-subtle:[^;]*#12ab34/);
});

test("anything that is not a six digit hex color is ignored, so a value can never inject styles", () => {
    for (const value of ["red", "#fff", "#12ab34; background:url(x)", "#12ab34}", "url(x)", 12, {}]) {
        assert.equal(accentCss(value), "", String(value));
    }
});

test("page titles carry the instance name", () => {
    assert.equal(pageTitle("Dashboard", "Acme Analytics"), "Dashboard · Acme Analytics");
    assert.equal(pageTitle("", "Acme Analytics"), "Acme Analytics");
    assert.equal(pageTitle("Dashboard", undefined), "Dashboard · Perysee");
});
