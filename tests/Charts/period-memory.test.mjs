import { test } from "node:test";
import assert from "node:assert/strict";
import { remember, restore } from "../../resources/js/period-memory.mjs";

const memoryStorage = () => {
    const data = new Map();
    return {
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => data.set(key, value),
        data,
    };
};
const brokenStorage = {
    getItem() {
        throw new Error("blocked");
    },
    setItem() {
        throw new Error("blocked");
    },
};

test("a remembered custom range is restored for the same site only", () => {
    const storage = memoryStorage();
    remember(storage, 3, "?from=2026-10-01&to=2026-10-15&filters[country][]=FR");
    assert.equal(restore(storage, 3, ""), "from=2026-10-01&to=2026-10-15");
    assert.equal(restore(storage, 4, ""), null);
});

test("a preset and the comparison mode are remembered together", () => {
    const storage = memoryStorage();
    remember(storage, 3, "?period=30d&compare=previous");
    assert.equal(restore(storage, 3, ""), "period=30d&compare=previous");
});

test("an explicit period in the URL always wins and nothing is restored", () => {
    const storage = memoryStorage();
    remember(storage, 3, "?period=30d");
    assert.equal(restore(storage, 3, "?period=7d"), null);
    assert.equal(restore(storage, 3, "?from=2026-10-01&to=2026-10-02"), null);
});

test("a comparison toggle in the URL is kept when the period is restored", () => {
    const storage = memoryStorage();
    remember(storage, 3, "?period=30d");
    assert.equal(restore(storage, 3, "?compare=previous"), "period=30d&compare=previous");
});

test("urls without a period do not erase what was remembered", () => {
    const storage = memoryStorage();
    remember(storage, 3, "?period=30d");
    remember(storage, 3, "?value=/pricing");
    assert.equal(restore(storage, 3, ""), "period=30d");
});

test("invalid stored values are ignored", () => {
    const storage = memoryStorage();
    storage.setItem("perysee-period:3", JSON.stringify({ period: "forever", from: "x", to: "y" }));
    assert.equal(restore(storage, 3, ""), null);
    storage.setItem("perysee-period:3", "{not json");
    assert.equal(restore(storage, 3, ""), null);
});

test("unavailable storage never throws", () => {
    assert.doesNotThrow(() => remember(brokenStorage, 3, "?period=7d"));
    assert.equal(restore(brokenStorage, 3, ""), null);
    assert.equal(restore(null, 3, ""), null);
});
