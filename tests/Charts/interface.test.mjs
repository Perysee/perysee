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
