<script setup>
// Public, read-only report. This page deliberately does not use WorkspaceLayout or ReportPage: it only knows the
// props listed below, which the server builds from the share link alone. Anything else is ignored.
import { computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import TrafficChart from "../../Components/TrafficChart.vue";
import BreakdownTable from "../../Components/BreakdownTable.vue";
import DateRange from "../../Components/DateRange.vue";
import ColorModeButton from "../../Components/ColorModeButton.vue";
import { queryUrl } from "../../interface-query.mjs";
const props = defineProps({
    site: { type: Object, required: true },
    sections: { type: Array, default: () => [] },
    period: { type: Object, required: true },
    today: { type: String, required: true },
    report: { type: Object, default: null },
    series: { type: Array, default: () => [] },
    dimensionOptions: { type: Array, default: () => [] },
    dimension: { type: String, default: null },
    dimensionReport: { type: Object, default: null },
});
const page = usePage();
const format = (value) => (value === null ? "—" : new Intl.NumberFormat("en").format(value));
const totals = computed(() => [
    { key: "pageviews", label: "Pageviews" },
    { key: "sessions", label: "Sessions started" },
    { key: "custom_events", label: "Custom events" },
]);
const labels = { path: "Pages", source: "Sources", campaign: "Campaigns", device: "Devices", device_brand: "Brands", browser: "Browsers", country: "Countries", event: "Events" };
const chartSeries = computed(() => (props.dimensionReport && !props.report ? props.dimensionReport.series : props.series));
const choose = (dimension) =>
    router.get(queryUrl(page.props.currentUrl, { from: props.period.from, to: props.period.to, dimension }), {}, { preserveScroll: true });
const freshness = computed(() =>
    props.report?.freshness?.aggregated_at
        ? new Intl.DateTimeFormat("en", { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit", timeZone: props.site.timezone }).format(new Date(props.report.freshness.aggregated_at))
        : null,
);
</script>
<template>
    <UApp>
        <div class="min-h-screen bg-default text-default">
            <div class="border-b border-default bg-elevated/50">
                <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-2 text-sm text-muted">
                    <span class="flex items-center gap-2"><UIcon name="i-tabler-eye" aria-hidden="true" />Shared view, read-only</span>
                    <ColorModeButton />
                </div>
            </div>
            <main class="mx-auto max-w-5xl space-y-6 px-4 py-6">
                <header class="flex flex-wrap items-end justify-between gap-4">
                    <div class="min-w-0">
                        <h1 class="break-words">{{ site.name }}</h1>
                        <p class="text-sm text-muted break-all">{{ site.domain }}</p>
                        <p v-if="freshness" class="text-sm text-muted">Last consolidated {{ freshness }} ({{ site.timezone }})</p>
                    </div>
                    <DateRange :period="period" :today="today" :timezone="site.timezone" />
                </header>
                <section v-if="report" class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Totals">
                    <UCard v-for="item in totals" :key="item.key">
                        <p class="text-sm text-muted">{{ item.label }}</p>
                        <p class="text-2xl font-semibold">{{ format(report.totals[item.key]) }}</p>
                    </UCard>
                </section>
                <UCard v-if="chartSeries.length">
                    <TrafficChart :series="chartSeries" :metric="report ? 'pageviews' : dimension === 'event' ? 'custom_events' : 'pageviews'" />
                </UCard>
                <nav v-if="dimensionOptions.length" class="flex flex-wrap gap-2" aria-label="Reports">
                    <UButton
                        v-for="option in dimensionOptions"
                        :key="option"
                        :label="labels[option] || option"
                        size="sm"
                        color="neutral"
                        :variant="dimension === option ? 'soft' : 'ghost'"
                        :aria-pressed="dimension === option"
                        @click="choose(option)"
                    />
                </nav>
                <UCard v-if="dimensionReport">
                    <BreakdownTable :rows="dimensionReport.data" :total="dimensionReport.total" :type="dimension" />
                </UCard>
            </main>
        </div>
    </UApp>
</template>
