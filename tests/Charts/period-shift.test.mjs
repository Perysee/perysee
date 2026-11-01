import { test } from "node:test";
import assert from "node:assert/strict";
import { shiftPeriod } from "../../resources/js/period-shift.mjs";

const today = "2026-10-31";

test("going back moves the range by its own length", () => {
    assert.deepEqual(shiftPeriod("2026-10-25", "2026-10-31", -1, today), {
        from: "2026-10-18",
        to: "2026-10-24",
    });
    assert.deepEqual(shiftPeriod("2026-10-31", "2026-10-31", -1, today), {
        from: "2026-10-30",
        to: "2026-10-30",
    });
});

test("going forward moves by the range length and never past today", () => {
    assert.deepEqual(shiftPeriod("2026-10-11", "2026-10-17", 1, today), {
        from: "2026-10-18",
        to: "2026-10-24",
    });
    assert.deepEqual(shiftPeriod("2026-10-20", "2026-10-28", 1, today), {
        from: "2026-10-23",
        to: "2026-10-31",
    });
});

test("there is nothing after a range that already ends today", () => {
    assert.equal(shiftPeriod("2026-10-25", "2026-10-31", 1, today), null);
});

test("month boundaries and leap days are handled in calendar days", () => {
    assert.deepEqual(shiftPeriod("2028-03-01", "2028-03-07", -1, "2028-04-01"), {
        from: "2028-02-23",
        to: "2028-02-29",
    });
});

test("invalid input is ignored", () => {
    assert.equal(shiftPeriod("nope", "2026-10-31", -1, today), null);
    assert.equal(shiftPeriod("2026-10-25", "2026-10-31", 0, today), null);
});

test("the current range is kept as a preset when the URL has one, as dates otherwise", async () => {
    const { currentRange } = await import("../../resources/js/period-shift.mjs");
    const period = { from: "2026-10-26", to: "2026-11-01" };
    assert.deepEqual(currentRange("/sites/1?period=7d&compare=previous", period), { period: "7d" });
    assert.deepEqual(currentRange("/sites/1?from=2026-10-01&to=2026-10-05", period), { from: "2026-10-26", to: "2026-11-01" });
    assert.deepEqual(currentRange("/sites/1", period), { from: "2026-10-26", to: "2026-11-01" });
    assert.deepEqual(currentRange("/sites/1?period=forever", period), { from: "2026-10-26", to: "2026-11-01" });
});
