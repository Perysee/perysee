import { test } from "node:test";
import assert from "node:assert/strict";
import { reportItems } from "../../resources/js/palette.mjs";
import { sectionList } from "../../resources/js/navigation.mjs";
import { applyColorMode } from "../../resources/js/color-mode.mjs";

const context = (overrides = {}) => ({
    url: "/sites/1?period=30d&compare=previous&filters[country][]=FR",
    currentUrl: "https://stats.test/sites/1",
    period: { from: "2026-10-03", to: "2026-11-01" },
    siteLinks: {
        sources: "https://stats.test/sites/1/sources",
        devices: "https://stats.test/sites/1/devices",
    },
    overview: true,
    brands: "https://stats.test/brands",
    ...overrides,
});
const params = (href) => new URL(href).searchParams;

test("period presets keep filters and the comparison mode", () => {
    const { period } = reportItems(context());
    const week = period.find(({ label }) => /7 days/.test(label));
    assert.equal(params(week.to).get("period"), "7d");
    assert.equal(params(week.to).get("compare"), "previous");
    assert.equal(params(week.to).get("filters[country][]"), "FR");
});

test("the comparison item flips between starting and stopping", () => {
    const stop = reportItems(context()).period.find(({ label }) => /comparing/i.test(label));
    assert.match(stop.label, /^Stop comparing/);
    assert.equal(params(stop.to).get("compare"), null);
    assert.equal(params(stop.to).get("period"), "30d");
    const start = reportItems(context({ url: "/sites/1?period=30d" })).period.find(({ label }) => /compar/i.test(label));
    assert.match(start.label, /^Compare/);
    assert.equal(params(start.to).get("compare"), "previous");
});

test("comparison is only offered on the overview", () => {
    const { period } = reportItems(context({ overview: false }));
    assert.equal(period.some(({ label }) => /compar/i.test(label)), false);
});

test("AI providers and browsers jump to their report with a color logo", () => {
    const { filters } = reportItems(context());
    const claude = filters.find(({ label }) => /Claude/.test(label));
    assert.equal(claude.avatar.src, "https://stats.test/brands/color/claude.svg");
    assert.equal(params(claude.to).get("provider"), "claude");
    assert.equal(params(claude.to).get("source_group"), "ai");
    assert.ok(claude.to.startsWith("https://stats.test/sites/1/sources"));
    const firefox = filters.find(({ label }) => /Firefox/.test(label));
    assert.equal(firefox.avatar.src, "https://stats.test/brands/color/firefox.svg");
    assert.equal(params(firefox.to).get("dimension"), "browser");
    assert.equal(params(firefox.to).get("filters[browser][]"), "firefox");
    assert.equal(filters.filter(({ label }) => /Traffic from/.test(label)).length, 6);
});

test("rows with a logo carry no icon, which would hide the logo", () => {
    const { filters } = reportItems(context());
    for (const item of filters) {
        assert.ok(item.avatar, item.label);
        assert.equal(item.icon, undefined, item.label);
    }
});

test("everything is searchable by keyword, not only by label", () => {
    const { period, filters } = reportItems(context());
    for (const item of [...period, ...filters]) assert.ok(item.keywords?.length, item.label);
    for (const section of sectionList) assert.ok(section.keywords?.length, section.key);
    assert.ok(sectionList.find(({ key }) => key === "countries").keywords.includes("pays"));
    assert.ok(filters.find(({ label }) => /ChatGPT/.test(label)).keywords.includes("ia"));
});

test("without a site or period nothing site-specific is offered", () => {
    assert.deepEqual(reportItems(context({ siteLinks: null })), { period: [], filters: [] });
    assert.deepEqual(reportItems(context({ period: null })), { period: [], filters: [] });
});

test("color mode is applied to the page, stored, and storage failures are ignored", () => {
    const classes = new Set();
    const root = { classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)) } };
    const saved = {};
    applyColorMode(true, root, { setItem: (k, v) => (saved[k] = v) });
    assert.ok(classes.has("dark"));
    assert.equal(saved["perysee-color-mode"], "dark");
    applyColorMode(false, root, { setItem: (k, v) => (saved[k] = v) });
    assert.ok(!classes.has("dark"));
    assert.equal(saved["perysee-color-mode"], "light");
    assert.doesNotThrow(() => applyColorMode(true, root, { setItem() { throw new Error("blocked"); } }));
    assert.doesNotThrow(() => applyColorMode(true, root, null));
});

test("the current period and the active comparison are flagged as current", () => {
    const { period } = reportItems(context());
    const current = period.filter(({ active }) => active);
    assert.deepEqual(current.map(({ label }) => label), ["Last 30 days", "Stop comparing with the previous period"]);
    for (const item of current) assert.equal(item.suffix, "Current", item.label);
    const none = reportItems(context({ url: "/sites/1?from=2026-10-01&to=2026-10-05" })).period;
    assert.equal(none.some(({ active }) => active), false);
});
