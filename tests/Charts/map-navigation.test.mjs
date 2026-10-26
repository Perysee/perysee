import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { init, use, registerMap } from "echarts/core";
import { MapChart } from "echarts/charts";
import { SVGRenderer } from "echarts/renderers";
import { zoomMap } from "../../resources/js/map-navigation.mjs";
use([MapChart, SVGRenderer]);
registerMap(
    "zoom-test",
    JSON.parse(
        readFileSync(new URL("../../public/maps/world.json", import.meta.url)),
    ),
);
test("zoom changes real country geometry around a finite center and survives inverse zoom", () => {
    const chart = init(null, null, {
        renderer: "svg",
        ssr: true,
        width: 900,
        height: 420,
    });
    try {
        chart.setOption({
            animation: false,
            series: [
                {
                    type: "map",
                    map: "zoom-test",
                    roam: "move",
                    zoom: 1,
                    scaleLimit: { min: 1, max: 8 },
                },
            ],
        });
        const before = chart.convertToPixel({ seriesIndex: 0 }, [2, 46]);
        assert.ok(Math.abs(zoomMap(chart, 1.5) - 1.5) < 0.0001);
        const after = chart.convertToPixel({ seriesIndex: 0 }, [2, 46]);
        assert.ok(
            after.every(Number.isFinite),
            "zoom must keep the map on screen",
        );
        assert.ok(
            Math.abs(after[1] - before[1]) > 1,
            "country position must actually move",
        );
        const svg = chart.renderToSVGString();
        assert.doesNotMatch(svg, /NaN|Infinity/);
        zoomMap(chart, 1 / 1.5);
        const restored = chart.convertToPixel({ seriesIndex: 0 }, [2, 46]);
        assert.ok(
            restored.every(
                (value, index) => Math.abs(value - before[index]) < 0.001,
            ),
        );
        assert.ok(Math.abs(zoomMap(chart, 100) - 8) < 0.0001);
        assert.ok(Math.abs(zoomMap(chart, 0.001) - 1) < 0.0001);
    } finally {
        chart.dispose();
    }
});
