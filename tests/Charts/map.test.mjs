import { test } from "node:test";
import assert from "node:assert/strict";
import { mapOption } from "../../resources/js/map-options.mjs";
const theme = {
    primary: "#5516FF",
    text: "#111",
    border: "#ddd",
    background: "#fff",
    muted: "#eee",
    subtle: "#eee",
    font: "Geist",
};
const geo = {
    features: [
        { properties: { name: "FR", label: "France" } },
        { properties: { name: "US", label: "United States" } },
    ],
};
test("map keeps navigation state, bounds zoom and highlights the selected country", () => {
    const option = mapOption(
        geo,
        [
            { value: "FR", count: 12 },
            { value: "US", count: 0 },
            { value: "Unknown", count: 8 },
        ],
        theme,
        { zoom: 3, center: [2, 46], selected: "FR", wheel: false },
    );
    const series = option.series[0];
    assert.equal(series.zoom, 3);
    assert.deepEqual(series.center, [2, 46]);
    assert.equal(series.roam, "move");
    assert.deepEqual(series.scaleLimit, { min: 1, max: 8 });
    assert.deepEqual(
        series.data.map((row) => row.name),
        ["FR", "US"],
    );
    assert.equal(series.data[0].selected, true);
    assert.equal(
        option.tooltip.formatter({ name: "US", value: 0 }),
        "United States\n0 pageviews",
    );
    assert.equal(
        option.tooltip.formatter({ name: "FR", value: NaN }),
        "France\nNo measured data",
    );
    assert.equal(
        mapOption(geo, [], theme, { wheel: true }).series[0].roam,
        true,
    );
});
