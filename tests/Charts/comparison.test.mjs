import { test } from "node:test";
import assert from "node:assert/strict";
import {
    variation,
    describeVariation,
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
    assert.deepEqual(describeVariation(null), {
        text: "New",
        label: "No data in the previous period",
    });
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
