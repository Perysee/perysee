<script setup>
import { ref, onMounted, onBeforeUnmount, watch, computed } from "vue";
import {queryUrl, selectFilter} from "../interface-query.mjs";
import { usePage, router } from "@inertiajs/vue3";
import { zoomMap } from "../map-navigation.mjs";
const props = defineProps({
    series: { type: Array, default: () => [] },
    metric: { type: String, default: "pageviews" },
    compact: Boolean,
    countries: Array,
    map: Boolean,
    filterUrl: String,
    selectedCountry: [String, Array],
});
const page = usePage(),
    element = ref(),
    failed = ref(false),
    wheel = ref(false),
    expanded = ref(false),
    zoomLevel = ref(1);
let chart,
    observer,
    themeObserver,
    alive = true;
const empty = computed(
    () => !props.series.some((point) => (point[props.metric] ?? 0) > 0),
);
const unavailable = computed(
    () =>
        props.metric === "sessions" &&
        props.series.every((point) => point.sessions === null),
);
const options = () => {
    // Resolve CSS mixes and variables to actual colors for the SVG renderer.
    const style = getComputedStyle(element.value);
    const canvas = document.createElement("canvas");
    canvas.width = canvas.height = 1;
    const context = canvas.getContext("2d");
    const color = (property) => {
        const probe = document.createElement("span");
        probe.style.color = style.getPropertyValue(property);
        element.value.append(probe);
        const value = getComputedStyle(probe).color;
        probe.remove();
        // ECharts expects RGB, while color-mix resolves to CSS Color 4 syntax.
        if (!context) return value;
        context.clearRect(0, 0, 1, 1);
        context.fillStyle = value;
        context.fillRect(0, 0, 1, 1);
        const [r, g, b, a] = context.getImageData(0, 0, 1, 1).data;
        return `rgba(${r}, ${g}, ${b}, ${a / 255})`;
    };
    return {
        primary: color("--ui-primary"),
        text: color("--ui-text"),
        border: color("--ui-border"),
        background: color("--ui-bg"),
        muted: color("--ui-bg-accented"),
        subtle: color("--brand-subtle"),
        font: "Geist, sans-serif",
        compact: props.compact,
        reduced: matchMedia("(prefers-reduced-motion: reduce)").matches,
    };
};
async function draw() {
    if (!chart || props.map) return;
    const { lineOption } = await import("../chart-options.mjs");
    if (alive)
        chart.setOption(lineOption(props.series, props.metric, options()), {
            notMerge: true,
        });
}
onMounted(async () => {
    try {
        const echarts = await import("../vue-charts.js");
        if (!alive) return;
        chart = echarts.create(element.value);
        if (props.map) {
            await echarts.world(
                chart,
                page.props.assets.world,
                props.countries || [],
                options(),
                { wheel: wheel.value, selected: props.selectedCountry },
            );
            if (!alive) return;
            chart.on("georoam", () => {
                zoomLevel.value =
                    Math.round(
                        (chart?.getOption().series[0].zoom || 1) * 10000,
                    ) / 10000;
            });
            chart.on("click", (event) => {
                if (/^[A-Z]{2}$/.test(event.name)) {
                    const url = new URL(props.filterUrl);
                    const selection = selectFilter(page.url, "country", event.name);
                    url.href = queryUrl(url.href, {from: url.searchParams.get("from"), to: url.searchParams.get("to"), ...selection});
                    router.get(
                        url.href,
                        {},
                        { preserveState: true, preserveScroll: true },
                    );
                }
            });
        } else await draw();
        observer = new ResizeObserver(() => chart?.resize());
        observer.observe(element.value);
        themeObserver = new MutationObserver(async () => {
            if (!chart || !alive) return;
            if (props.map) {
                const echarts = await import("../vue-charts.js");
                if (alive)
                    await echarts.world(
                        chart,
                        page.props.assets.world,
                        props.countries || [],
                        options(),
                        { wheel: wheel.value, selected: props.selectedCountry },
                    );
            } else await draw();
        });
        themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ["class"],
        });
    } catch {
        failed.value = true;
        chart?.dispose();
        chart = null;
    }
});
watch(() => [props.series, props.metric], draw, { deep: true });
watch(
    () => [props.countries, props.selectedCountry],
    async () => {
        if (chart && props.map) {
            try {
                const echarts = await import("../vue-charts.js");
                if (alive)
                    await echarts.world(
                        chart,
                        page.props.assets.world,
                        props.countries || [],
                        options(),
                        { wheel: wheel.value, selected: props.selectedCountry },
                    );
            } catch {
                failed.value = true;
            }
        }
    },
    { deep: true },
);
function zoom(factor) {
    if (!chart) return;
    zoomMap(chart, factor);
}
async function resetMap() {
    if (!chart) return;
    const echarts = await import("../vue-charts.js");
    if (alive)
        await echarts.world(
            chart,
            page.props.assets.world,
            props.countries || [],
            options(),
            {
                reset: true,
                wheel: wheel.value,
                selected: props.selectedCountry,
            },
        );
    zoomLevel.value = 1;
}
watch(wheel, (value) => {
    chart?.setOption({ series: [{ roam: value ? true : "move" }] });
});
function mapKey(event) {
    if (!props.map || !chart) return;
    const direction = {
        ArrowLeft: [35, 0],
        ArrowRight: [-35, 0],
        ArrowUp: [0, 35],
        ArrowDown: [0, -35],
    }[event.key];
    if (direction) {
        event.preventDefault();
        chart.dispatchAction({
            type: "geoRoam",
            seriesIndex: 0,
            dx: direction[0],
            dy: direction[1],
        });
    } else if (["+", "=", "-", "Home"].includes(event.key)) {
        event.preventDefault();
        if (event.key === "Home") resetMap();
        else zoom(event.key === "-" ? 1 / 1.5 : 1.5);
    }
}
onBeforeUnmount(() => {
    alive = false;
    observer?.disconnect();
    themeObserver?.disconnect();
    chart?.dispose();
    chart = null;
});
</script>
<template>
    <div :class="{ 'map-expanded': expanded }">
        <div v-if="map" class="map-toolbar">
            <p class="text-xs text-muted">
                Drag to explore · select a country to filter
            </p>
            <div class="flex items-center gap-1">
                <span
                    class="text-xs text-muted tabular-nums mr-2"
                    aria-live="polite"
                    >{{ zoomLevel.toFixed(1) }}×</span
                ><UButton
                    icon="i-tabler-minus"
                    aria-label="Zoom out"
                    color="neutral"
                    variant="ghost"
                    :disabled="zoomLevel <= 1"
                    @click="zoom(1 / 1.5)"
                /><UButton
                    icon="i-tabler-plus"
                    aria-label="Zoom in"
                    color="neutral"
                    variant="ghost"
                    :disabled="zoomLevel >= 8"
                    @click="zoom(1.5)"
                /><UButton
                    icon="i-tabler-focus-centered"
                    aria-label="Reset map view"
                    color="neutral"
                    variant="ghost"
                    @click="resetMap"
                /><UButton
                    :icon="expanded ? 'i-tabler-minimize' : 'i-tabler-maximize'"
                    :aria-label="expanded ? 'Reduce map' : 'Expand map'"
                    color="neutral"
                    variant="ghost"
                    @click="expanded = !expanded"
                />
            </div>
        </div>
        <div
            ref="element"
            :class="
                map
                    ? 'map-element'
                    : compact
                      ? 'chart-element chart-mini'
                      : 'chart-element'
            "
            :tabindex="map ? 0 : undefined"
            @keydown="mapKey"
            :data-map-zoom="map ? zoomLevel : undefined"
            :role="map ? 'region' : 'img'"
            :aria-label="
                map
                    ? 'Country pageviews'
                    : 'Consolidated ' + metric + ' over time'
            "
            :data-chart-failed="failed || undefined"
        />
        <div v-if="map" class="map-help">
            <span>Arrow keys to pan · +/− to zoom · Home to reset</span
            ><USwitch v-model="wheel" size="sm" label="Scroll to zoom" />
        </div>
        <p v-if="failed" class="data-note px-5 pb-4">
            Chart unavailable. Exact values remain available in the table.
        </p>
        <p
            v-else-if="!map && !compact && empty"
            class="text-xs text-muted text-center pb-4"
        >
            {{
                unavailable
                    ? "Session measurement unavailable"
                    : "No measured activity in this selection"
            }}
        </p>
    </div>
</template>
