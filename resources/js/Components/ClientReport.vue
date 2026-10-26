<script setup>
import { computed, ref } from "vue";
import { usePage, router } from "@inertiajs/vue3";
import { queryUrl } from "../interface-query.mjs";
import { describeClient } from "../client-labels.mjs";
import BreakdownTable from "./BreakdownTable.vue";
import TrafficChart from "./TrafficChart.vue";
const props = defineProps([
    "dimension",
    "dimensionReport",
    "technologyCoverage",
    "period",
    "report",
    "siteLinks",
]);
const page = usePage(),
    trend = ref(false);
const tabs = [
    { key: "device", label: "Device types", icon: "i-tabler-devices" },
    { key: "device_brand", label: "Brands", icon: "i-tabler-device-mobile" },
    { key: "browser", label: "Browsers", icon: "i-tabler-world" },
];
const title = computed(
    () => tabs.find((tab) => tab.key === props.dimension)?.label,
);
const query = computed(() => ({
    from: props.period.from,
    to: props.period.to,
    dimension: props.dimension,
}));
const selected = computed(() =>
    new URL(page.url, "http://perysee.local").searchParams.get("value"),
);
const measured = computed(() => props.technologyCoverage[props.dimension] || 0);
const rows = computed(() =>
    props.dimensionReport.data.map((row) => ({
        ...row,
        ...describeClient(props.dimension, row.value),
    })),
);
const count = (value) => new Intl.NumberFormat("en").format(value);
const filter = (value) =>
    router.get(
        queryUrl(props.siteLinks.devices, { ...query.value, value }),
        {},
        { preserveScroll: true },
    );
</script>
<template>
    <div class="client-report">
        <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
            <UButtonGroup
                ><UButton
                    v-for="tab in tabs"
                    :key="tab.key"
                    :label="tab.label"
                    :icon="tab.icon"
                    :variant="dimension === tab.key ? 'soft' : 'ghost'"
                    :color="dimension === tab.key ? 'primary' : 'neutral'"
                    :aria-pressed="dimension === tab.key"
                    @click="
                        router.get(
                            queryUrl(siteLinks.devices, {
                                from: period.from,
                                to: period.to,
                                dimension: tab.key,
                            }),
                        )
                    " /></UButtonGroup
            ><span class="text-xs text-muted"
                >{{ count(measured) }} /
                {{ count(report.totals.pageviews) }} pageviews measured</span
            >
        </div>
        <div v-if="selected" class="selection-banner mb-5">
            <span
                ><UIcon name="i-tabler-filter" />
                {{ describeClient(dimension, selected).label }}</span
            ><UButton
                label="Clear selection"
                icon="i-tabler-x"
                size="xs"
                color="neutral"
                variant="ghost"
                @click="filter(null)"
            />
        </div>
        <template v-if="dimension === 'device'">
            <div v-if="rows.length" class="device-grid">
                <button
                    v-for="row in rows"
                    :key="row.value"
                    class="device-card"
                    type="button"
                    @click="filter(row.value)"
                >
                    <span class="device-illustration"
                        ><UIcon class="size-14" :name="row.icon" /></span
                    ><span class="text-sm font-medium">{{ row.label }}</span
                    ><strong
                        class="text-3xl font-medium tabular-nums tracking-tight"
                        >{{ count(row.count) }}</strong
                    ><span class="device-share"
                        ><span
                            :style="{
                                width: dimensionReport.total
                                    ? (row.count / dimensionReport.total) *
                                          100 +
                                      '%'
                                    : '0%',
                            }" /></span
                    ><span class="text-xs text-muted"
                        >{{
                            dimensionReport.total
                                ? (
                                      (row.count / dimensionReport.total) *
                                      100
                                  ).toFixed(1)
                                : 0
                        }}% of this selection
                        <UIcon name="i-tabler-arrow-up-right"
                    /></span>
                </button>
            </div>
            <UEmpty
                v-else
                title="No device data in this period"
                description="Device categories become available as compatible pageviews are collected."
                icon="i-tabler-devices"
            />
        </template>
        <div v-else class="client-detail-grid">
            <div class="report-panel">
                <header class="panel-heading">
                    <h2>{{ title }}</h2>
                    <span class="text-xs text-muted"
                        >{{ dimensionReport.distinct_values }}
                        {{
                            dimension === "browser" ? "families" : "groups"
                        }}</span
                    >
                </header>
                <BreakdownTable
                    :key="dimension"
                    :rows="rows"
                    :type="dimension"
                    :total="dimensionReport.total"
                    :report-url="siteLinks.devices"
                    :query="query"
                />
            </div>
            <aside class="client-explainer">
                <UIcon
                    :name="
                        dimension === 'browser'
                            ? 'i-tabler-world'
                            : 'i-tabler-device-mobile'
                    "
                    class="size-8 text-primary"
                />
                <h2>
                    {{
                        dimension === "browser"
                            ? "The browsers behind your traffic"
                            : "A brand when the device reveals it"
                    }}
                </h2>
                <p>
                    {{
                        dimension === "browser"
                            ? "Browser families are identified from the request header. Some browsers present themselves as Chrome, so this is an approximation."
                            : "Brands are shown only when the request gives an explicit indication. A Windows computer is not automatically classified as Microsoft."
                    }}
                </p>
                <p>
                    Unknown means the information was measured but could not be
                    identified. Older visits without this dimension are
                    excluded, rather than reconstructed.
                </p>
                <span class="text-xs text-muted flex items-center gap-1"
                    >No raw User-Agent, device model or browser version is
                    stored.</span
                >
            </aside>
        </div>
        <UAlert
            v-if="measured < report.totals.pageviews"
            color="neutral"
            variant="soft"
            icon="i-tabler-info-circle"
            title="Partial measurement"
            :description="`${count(report.totals.pageviews - measured)} pageviews do not have this dimension. New collection does not reconstruct older visits.`"
            class="mt-6"
        />
        <UCollapsible v-model:open="trend" class="report-panel mt-6"
            ><UButton
                color="neutral"
                variant="ghost"
                :icon="
                    trend ? 'i-tabler-chevron-down' : 'i-tabler-chevron-right'
                "
                label="See activity for this selection"
                class="m-3" /><template #content
                ><TrafficChart
                    :series="dimensionReport.series"
                    metric="pageviews" /></template
        ></UCollapsible>
    </div>
</template>
