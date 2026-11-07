import { test } from "node:test";
import assert from "node:assert/strict";
import {
    sectionGroups,
    sectionList,
    groupedItems,
    limitSites,
} from "../../resources/js/navigation.mjs";

test("every site section appears exactly once, in its group", () => {
    assert.deepEqual(
        sectionList.map(({ key }) => key),
        [
            "overview", "pages", "sources", "campaigns",
            "devices", "countries", "sessions",
            "events", "funnels", "goals", "journeys",
            "realtime",
        ],
    );
    assert.deepEqual(
        sectionGroups.map(({ label }) => label),
        ["Traffic", "Audience", "Behavior", "Live"],
    );
});

test("grouped items interleave a heading before each group", () => {
    const items = groupedItems(({ key, label }) => ({ key, label }));
    assert.deepEqual(items[0], { label: "Traffic", type: "label" });
    assert.equal(items[1].key, "overview");
    assert.equal(items.filter(({ type }) => type === "label").length, 4);
    assert.equal(items.length, 4 + sectionList.length);
});

test("site lists are capped but report how many are hidden", () => {
    const sites = Array.from({ length: 12 }, (_, id) => ({ id, name: `s${id}` }));
    assert.deepEqual(limitSites(sites, 8), { visible: sites.slice(0, 8), hidden: 4 });
    assert.deepEqual(limitSites(sites.slice(0, 3), 8), { visible: sites.slice(0, 3), hidden: 0 });
});
