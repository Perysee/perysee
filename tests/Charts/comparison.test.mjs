import { test } from "node:test";
import assert from "node:assert/strict";
import {
    variation,
    describeVariation,
    describeComparison,
} from "../../resources/js/comparison.mjs";
import { lineOption } from "../../resources/js/chart-options.mjs";
import { reportFilters, queryUrl } from "../../resources/js/interface-query.mjs";

test("variation reports the relative change against the previous period", () => {
    assert.deepEqual(variation(150, 100), { percent: 50, direction: "up" });
    assert.deepEqual(variation(75, 100), { percent: -25, direction: "down" });
    assert.deepEqual(variation(100, 100), { percent: 0, direction: "flat" });
    assert.deepEqual(variation(10, 3), { percent: 233.3, direction: "up" });
});

test("variation is undefined when there is nothing to compare with", () => {
    assert.equal(variation(10, 0), null);
    assert.equal(variation(10, null), null);
    assert.equal(variation(null, 10), null);
    assert.deepEqual(variation(0, 0), { percent: 0, direction: "flat" });
});

test("variation is described with a sign and in words", () => {
    assert.deepEqual(describeVariation(variation(150, 100)), {
        text: "+50 %",
        label: "Up 50 % compared with the previous period",
    });
    assert.deepEqual(describeVariation(variation(75, 100)), {
        text: "−25 %",
        label: "Down 25 % compared with the previous period",
    });
    assert.deepEqual(describeVariation(variation(5, 5)), {
        text: "0 %",
        label: "Unchanged compared with the previous period",
    });
});

test("null is not New: an unavailable variation is an em dash", () => {
    const missing = describeVariation(null);
    assert.equal(missing.text, "—");
    assert.doesNotMatch(JSON.stringify(missing), /New/);
    const unavailable = describeComparison(12, null, { percent_allowed: false, reason: "unavailable" });
    assert.equal(unavailable.percent, null);
    assert.equal(unavailable.previousText, "—");
    assert.equal(unavailable.delta, null);
    assert.equal(unavailable.direction, "none");
    assert.equal(unavailable.reasonText, "Not measured in one of the periods");
    assert.doesNotMatch(JSON.stringify(unavailable), /New|Up|Down/);
});

test("the server reliability decides whether a percent is shown", () => {
    const allowed = describeComparison(150, 100, { percent_allowed: true, reason: null });
    assert.equal(allowed.percent, "+50 %");
    assert.equal(allowed.direction, "up");
    assert.equal(allowed.deltaText, "+50");
    assert.equal(allowed.previousText, "100");
    assert.equal(allowed.label, "Up 50 % compared with the previous period");
    assert.equal(allowed.reasonText, null);
    const withheld = describeComparison(150, 100, { percent_allowed: false, reason: "current_period" });
    assert.equal(withheld.percent, null);
    assert.equal(withheld.direction, "none");
    assert.equal(withheld.deltaText, "+50");
    assert.equal(withheld.previousText, "100");
    assert.equal(withheld.reasonText, "The current period is not over yet");
    assert.doesNotMatch(JSON.stringify(withheld), /Up|Down/);
    const zero = describeComparison(3, 0, { percent_allowed: false, reason: "previous_zero" });
    assert.equal(zero.previousText, "0");
    assert.equal(zero.reasonText, "No activity in the previous period");
    assert.equal(describeComparison(3, 4, undefined).percent, null);
});

test("the comparison line is dashed and aligned with the current period by index", () => {
    const current = [
        { label: "10-18", pageviews: 4 },
        { label: "10-19", pageviews: 6 },
        { label: "10-20", pageviews: 8 },
    ];
    const previous = [
        { label: "10-11", pageviews: 1 },
        { label: "10-12", pageviews: 2 },
    ];
    const option = lineOption(current, "pageviews", { comparison: previous });
    assert.equal(option.series.length, 2);
    assert.deepEqual(option.series[0].data, [4, 6, 8]);
    assert.deepEqual(option.series[1].data, [1, 2, null]);
    assert.equal(option.series[1].lineStyle.type, "dashed");
    assert.equal(option.series[1].name, "Previous period");
    assert.equal(option.series[1].areaStyle, undefined);
    assert.deepEqual(option.xAxis.data, ["10-18", "10-19", "10-20"]);
    const text = option.tooltip.formatter([
        { dataIndex: 1, seriesName: "Pageviews", value: 6 },
        { dataIndex: 1, seriesName: "Previous period", value: 2 },
    ]);
    assert.match(text, /10-19/);
    assert.match(text, /Previous period \(10-12\): 2/);
});

test("previous points are aligned by rank, with a distinct symbol and no hover-only date", () => {
    const current = [
        { label: "00:00 CEST", pageviews: 1 },
        { label: "01:00 CEST", pageviews: 2 },
        { label: "02:00 CEST", pageviews: 3 },
        { label: "02:00 CET", pageviews: 4 },
        { label: "03:00 CET", pageviews: 5 },
    ];
    const previous = [
        { label: "00:00 CEST", day: "2026-10-24", rank: 0, pageviews: 9 },
        { label: "02:00 CEST", day: "2026-10-24", rank: 2, pageviews: 8 },
        { label: "03:00 CEST", day: "2026-10-24", rank: 4, pageviews: 7 },
        { label: "04:00 CEST", day: "2026-10-24", rank: null, pageviews: 6 },
    ];
    const option = lineOption(current, "pageviews", { comparison: previous });
    assert.deepEqual(option.series[1].data, [9, null, 8, null, 7]);
    assert.equal(option.series[1].connectNulls, false);
    assert.notEqual(option.series[1].symbol, option.series[0].symbol ?? "circle");
    assert.equal(option.series[1].showSymbol, true);
    assert.match(option.aria.label.description, /previous period/);
    const text = option.tooltip.formatter([
        { dataIndex: 2, seriesName: "Pageviews", value: 3 },
        { dataIndex: 2, seriesName: "Previous period", value: 8 },
    ]);
    assert.match(text, /Previous period \(2026-10-24 02:00 CEST\): 8/);
    const gap = option.tooltip.formatter([
        { dataIndex: 3, seriesName: "Pageviews", value: 4 },
        { dataIndex: 3, seriesName: "Previous period", value: null },
    ]);
    assert.match(gap, /Previous period: —/);
});

test("without a comparison the chart keeps a single series and the default tooltip", () => {
    const option = lineOption([{ label: "a", pageviews: 1 }], "pageviews");
    assert.equal(option.series.length, 1);
    assert.equal(option.tooltip.formatter, undefined);
    assert.equal(option.legend, undefined);
});

test("the comparison mode survives date and filter navigation", () => {
    const filters = reportFilters("/sites/3?from=2026-10-18&to=2026-10-24&compare=previous");
    assert.equal(filters.compare, "previous");
    const url = new URL(queryUrl("https://stats.test/sites/3", { ...filters, period: "30d" }));
    assert.equal(url.searchParams.get("compare"), "previous");
});

test("every echarts component used by the chart options is registered", async () => {
    const { readFileSync } = await import("node:fs");
    const registered = readFileSync(new URL("../../resources/js/vue-charts.js", import.meta.url), "utf8");
    const option = lineOption([{ label: "a", pageviews: 1 }], "pageviews", { comparison: [{ label: "b", pageviews: 1 }] });
    const needed = { legend: "LegendComponent", tooltip: "TooltipComponent", grid: "GridComponent", aria: "AriaComponent" };
    for (const [key, component] of Object.entries(needed))
        if (option[key]) assert.match(registered, new RegExp(`\\b${component}\\b`), `${key} needs ${component}`);
});
