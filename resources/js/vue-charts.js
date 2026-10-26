import { mapOption } from "./map-options.mjs";
import { init, use, registerMap } from "echarts/core";
import { LineChart, MapChart } from "echarts/charts";
import {
    GridComponent,
    TooltipComponent,
    VisualMapComponent,
    AriaComponent,
} from "echarts/components";
import { SVGRenderer } from "echarts/renderers";
use([
    LineChart,
    MapChart,
    GridComponent,
    TooltipComponent,
    VisualMapComponent,
    AriaComponent,
    SVGRenderer,
]);
let worldData;
export const create = (element) => init(element, null, { renderer: "svg" });
export async function world(chart, url, rows, theme, state = {}) {
    worldData ??= fetch(url, { credentials: "same-origin" }).then(
        (response) => {
            if (!response.ok) throw Error("Map unavailable");
            return response.json();
        },
    );
    const geo = await worldData;
    registerMap("perysee-world", geo);
    const previous = chart.getOption()?.series?.[0];
    const view = state.reset
        ? {}
        : { zoom: previous?.zoom, center: previous?.center };
    chart.setOption(mapOption(geo, rows, theme, { ...view, ...state }), {
        notMerge: true,
    });
}
