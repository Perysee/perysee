import { test } from "node:test";
import assert from "node:assert/strict";
import {
    queryUrl,
    reportFilters,
} from "../../resources/js/interface-query.mjs";
test("changing dates preserves source filters and drops pagination", () => {
    const filters = reportFilters(
        "/sites/3/sources?page=2&value=claude.ai&source_group=ai&provider=claude",
    );
    const url = new URL(
        queryUrl("https://stats.test/sites/3/sources", {
            ...filters,
            from: "2026-10-01",
            to: "2026-10-25",
        }),
    );
    assert.equal(url.searchParams.get("value"), "claude.ai");
    assert.equal(url.searchParams.get("provider"), "claude");
    assert.equal(url.searchParams.get("from"), "2026-10-01");
    assert.equal(url.searchParams.has("page"), false);
});

test("calendar changes keep the active device or browser report", () => {
    const filters = reportFilters(
        "/sites/3/devices?dimension=browser&value=safari&page=3",
    );
    const url = new URL(
        queryUrl("https://stats.test/sites/3/devices", {
            ...filters,
            from: "2026-10-01",
            to: "2026-10-24",
        }),
    );
    assert.equal(url.searchParams.get("dimension"), "browser");
    assert.equal(url.searchParams.get("value"), "safari");
    assert.equal(url.searchParams.has("page"), false);
});

test('crossed filters survive date changes and serialize as Laravel arrays', () => {
    const filters = reportFilters('/sites/3/pages?filters[country][]=FR&filters[country][]=BE&filters[device][]=mobile&page=2');
    const url = new URL(queryUrl('https://stats.test/sites/3/pages', {...filters, from:'2026-10-01'}));
    assert.deepEqual(url.searchParams.getAll('filters[country][]'), ['FR', 'BE']);
    assert.deepEqual(url.searchParams.getAll('filters[device][]'), ['mobile']);
    assert.equal(url.searchParams.has('page'), false);
});

test("a path stays a path, so the link it builds is followed by Inertia instead of reloading the page", () => {
    const url = queryUrl("/sites/3/sources", { period: "30d", filters: { source: ["news"] } });

    assert.equal(url.startsWith("/sites/3/sources?"), true);
    assert.doesNotMatch(url, /^[a-z]+:/i);
    assert.equal(new URL(url, "http://perysee.local").searchParams.get("period"), "30d");
});

test("an absolute address keeps its origin and a path loses any previous query", () => {
    assert.equal(queryUrl("https://stats.test/sites/3", { period: "7d" }), "https://stats.test/sites/3?period=7d");
    assert.equal(queryUrl("/sites/3?old=1", { period: "7d" }), "/sites/3?period=7d");
});
