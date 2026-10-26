<script setup>
import { computed, ref, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import TrafficChart from "./TrafficChart.vue";
import DateRange from "./DateRange.vue";
import BreakdownTable from "./BreakdownTable.vue";
import ClientReport from "./ClientReport.vue";
import CampaignTable from "./CampaignTable.vue";
import { queryUrl, reportFilters } from "../interface-query.mjs";
const props = defineProps([
    "website",
    "canUpdate",
    "siteLinks",
    "section",
    "report",
    "period",
    "today",
    "series",
    "dimension",
    "dimensions",
    "dimensionReport",
    "breakdowns",
    "recent",
    "receptionTotals",
    "technologyCoverage",
    "countryContext",
]);
const page = usePage();
const current = computed(() => props.section || "overview"),
    overview = computed(() => current.value === "overview"),
    isSessions = computed(() => current.value === "sessions"),
    isRecent = computed(() => current.value === "realtime"),
    isDimension = computed(
        () => !overview.value && !isSessions.value && !isRecent.value,
    );
const metric = ref(
    isSessions.value
        ? "sessions"
        : current.value === "events"
          ? "custom_events"
          : "pageviews",
);
const series = computed(() =>
    isDimension.value
        ? props.dimensionReport?.series || []
        : props.series || [],
);
const labels = {
    pageviews: "Pageviews",
    sessions: "Sessions started",
    custom_events: "Custom events",
};
const rows = computed(() =>
    isDimension.value
        ? props.dimensionReport?.data || []
        : props.dimensions || [],
);
const query = computed(() => ({
    from: props.period.from,
    to: props.period.to,
    ...reportFilters(page.url),
}));
const exportUrl = computed(() =>
    queryUrl(props.siteLinks.export, {
        ...query.value,
        ...(isDimension.value ? { dimension: props.dimension } : {}),
    }),
);
const group = ref(reportFilters(page.url).source_group || "all"),
    provider = ref(reportFilters(page.url).provider || ""),
    value = ref(reportFilters(page.url).value || "");
watch(
    () => page.url,
    () => {
        const filters = reportFilters(page.url);
        group.value = filters.source_group || "all";
        provider.value = filters.provider || "";
        value.value = filters.value || "";
    },
);
const filter = () =>
    router.get(
        queryUrl(page.props.currentUrl, {
            ...query.value,
            value: value.value,
            source_group: group.value,
            provider: provider.value,
        }),
        {},
        { preserveScroll: true, preserveState: true },
    );
const headings = {
    path: "Popular pages",
    source: "Sources",
    campaign: "Campaigns",
    device: "Devices",
    event: "Custom events",
    country: "Countries",
};
const icons = {
    path: "i-tabler-file-text",
    source: "i-tabler-arrow-up-right",
    campaign: "i-tabler-speakerphone",
    device: "i-tabler-device-desktop",
    event: "i-tabler-bolt",
    country: "i-tabler-world",
};
const format = (value) =>
    value === null ? "—" : new Intl.NumberFormat("en").format(value);
const chartColumns = computed(() => [
    { accessorKey: "label", header: "Period" },
    ...(overview.value ? Object.keys(labels) : [metric.value]).map((key) => ({
        accessorKey: key,
        header: labels[key],
    })),
]);
const recentColumns = [
    { accessorKey: "event_name", header: "Event" },
    { accessorKey: "path", header: "Page" },
    { accessorKey: "received_at", header: "Received (UTC)" },
];
const notesOpen = ref(false),
    dataOpen = ref(false);
const lastConsolidation = computed(() =>
    props.report.freshness.aggregated_at
        ? new Intl.DateTimeFormat("en", {
              month: "short",
              day: "numeric",
              hour: "2-digit",
              minute: "2-digit",
              timeZone: props.website.timezone,
          }).format(new Date(props.report.freshness.aggregated_at))
        : "Not yet consolidated",
);
</script>
<template>
    <UBadge
        v-if="website.is_demo"
        color="warning"
        variant="subtle"
        size="sm"
        class="mb-4"
        >Demonstration data · synthetic events</UBadge
    >
    <div class="page-heading">
        <div>
            <p class="eyebrow">
                {{ overview ? "Site overview" : website.name }}
            </p>
            <h1>
                {{
                    overview
                        ? website.name
                        : isRecent
                          ? "Recent activity"
                          : isSessions
                            ? "Sessions"
                            : current === "devices"
                              ? "Devices & browsers"
                              : headings[dimension]
                }}
            </h1>
            <p class="text-xs text-muted">
                {{ website.domain }} <span class="mx-2 text-dimmed">/</span
                >{{ website.timezone }}
            </p>
        </div>
        <div class="flex gap-2">
            <UButton
                v-if="isRecent"
                icon="i-tabler-refresh"
                color="neutral"
                variant="outline"
                label="Refresh"
                @click="router.reload({ preserveScroll: true })"
            /><UButton
                v-else
                :href="exportUrl"
                external
                icon="i-tabler-download"
                color="neutral"
                variant="outline"
                class="text-xs"
                ><span class="hidden sm:inline">{{
                    isDimension ? "Export table CSV" : "Export daily CSV"
                }}</span></UButton
            ><UButton
                v-if="canUpdate"
                :to="siteLinks.edit"
                icon="i-tabler-settings"
                color="neutral"
                variant="ghost"
                aria-label="Edit site"
            />
        </div>
    </div>
    <div v-if="!isRecent" class="report-toolbar">
        <div class="text-xs text-muted flex flex-wrap gap-2 items-center">
            <span
                class="size-1.5 rounded-full"
                :class="report.freshness.pending ? 'bg-warning' : 'bg-success'"
            /><span>{{
                report.freshness.pending
                    ? "Consolidation pending"
                    : "Consolidated data"
            }}</span
            ><span class="hidden xl:inline text-dimmed"
                >· {{ lastConsolidation }}</span
            >
        </div>
        <DateRange
            :period="period"
            :today="today"
            :timezone="website.timezone"
        />
    </div>
    <UAlert
        v-if="!isRecent && report.freshness.pending"
        icon="i-tabler-clock"
        title="Consolidation pending"
        description="New events are waiting for the next consolidation. Reports are incomplete until it finishes."
        color="warning"
        variant="subtle"
        class="mb-5"
    />
    <template v-if="isRecent">
        <p class="text-sm text-muted mb-6">
            Reception check · confirmed events from the last 15 minutes,
            separate from consolidated reports.
        </p>
        <div class="grid sm:grid-cols-3 gap-3 mb-6">
            <UCard
                v-for="[key, label] in [
                    ['events', 'Events received'],
                    ['pageviews', 'Pageviews'],
                    ['custom_events', 'Custom events'],
                ]"
                :key="key"
                ><span class="metric-label">{{ label }}</span
                ><strong class="metric-value">{{
                    format(receptionTotals[key])
                }}</strong></UCard
            >
        </div>
        <UCard :ui="{ body: 'p-0 sm:p-0' }"
            ><UTable
                :data="recent || []"
                :columns="recentColumns"
                empty="No events received in the last fifteen minutes."
                ><template #event_name-cell="{ row }">{{
                    row.original.event_name || "Pageview"
                }}</template></UTable
            >
            <p class="text-xs text-muted p-4 border-t border-default">
                Latest 100 events · updates when refreshed · no visitor
                identifiers displayed
            </p></UCard
        >
    </template>
    <ClientReport v-else-if="current === 'devices'" v-bind="props" />
    <template v-else>
        <div :class="['report-composition', 'composition-' + current]">
            <template v-if="current === 'countries'"
                ><UAlert
                    color="neutral"
                    variant="soft"
                    icon="i-tabler-world"
                    :title="`Country coverage: ${report.availability.countries}`"
                    :description="`${format(report.coverage.country_pageviews)} of ${format(report.totals.pageviews)} pageviews measured. Unknown countries are not placed on the map.`"
                    class="mb-5"
                />
                <div class="report-panel chart-panel mb-6">
                    <header class="panel-heading">
                        <h2>Explore your audience</h2>
                        <UButton
                            v-if="value"
                            :label="'Clear ' + value"
                            icon="i-tabler-x"
                            color="neutral"
                            variant="soft"
                            size="xs"
                            @click="
                                value = '';
                                filter();
                            "
                        />
                    </header>
                    <TrafficChart
                        map
                        :countries="countryContext"
                        :selected-country="value"
                        :filter-url="
                            queryUrl(siteLinks.countries, {
                                from: period.from,
                                to: period.to,
                            })
                        "
                    />
                    <p class="text-xs text-muted px-5 pb-4">
                        <a
                            href="https://db-ip.com"
                            rel="noreferrer"
                            class="underline"
                            >IP Geolocation by DB-IP</a
                        >
                        · country approximation only
                    </p>
                </div></template
            >
            <div
                class="report-panel chart-panel report-trend"
                :class="{ 'compact-trend': isDimension }"
            >
                <div
                    v-if="overview"
                    class="metric-grid border-b border-default"
                >
                    <button
                        v-for="[key, label] in Object.entries(labels)"
                        :key="key"
                        type="button"
                        class="metric-cell"
                        :aria-pressed="metric === key"
                        @click="metric = key"
                    >
                        <span class="metric-label"
                            >{{ label
                            }}<UBadge
                                v-if="
                                    key === 'sessions' &&
                                    report.availability.sessions === 'partial'
                                "
                                color="warning"
                                variant="subtle"
                                size="xs"
                                >Partial</UBadge
                            ></span
                        ><strong class="metric-value">{{
                            format(report.totals[key])
                        }}</strong
                        ><span class="metric-caption">{{
                            key === "pageviews"
                                ? "Pages viewed in this period"
                                : key === "sessions"
                                  ? report.totals.sessions === null
                                      ? "Session measurement unavailable"
                                      : "Counted at the first pageview"
                                  : "Actions tracked on your site"
                        }}</span>
                    </button>
                </div>
                <div
                    v-else-if="isSessions"
                    class="metric-grid border-b border-default"
                >
                    <div class="metric-cell">
                        <span class="metric-label">Sessions started</span
                        ><strong class="metric-value">{{
                            format(report.totals.sessions)
                        }}</strong>
                    </div>
                    <div class="metric-cell">
                        <span class="metric-label">Measured pageviews</span
                        ><strong class="metric-value">{{
                            format(report.coverage.measured_pageviews)
                        }}</strong>
                    </div>
                    <div class="metric-cell">
                        <span class="metric-label">Session coverage</span
                        ><strong class="metric-value !text-base capitalize">{{
                            report.availability.sessions
                        }}</strong>
                    </div>
                </div>
                <div
                    v-else
                    class="grid grid-cols-2 sm:grid-cols-3 border-b border-default"
                >
                    <div class="metric-cell">
                        <span class="metric-label">{{
                            dimension === "event"
                                ? "Tracked events"
                                : "Attributed pageviews"
                        }}</span
                        ><strong class="metric-value">{{
                            format(dimensionReport.total)
                        }}</strong>
                    </div>
                    <div class="metric-cell">
                        <span class="metric-label">{{
                            dimension === "country"
                                ? "Country groups"
                                : dimension === "event"
                                  ? "Event names"
                                  : headings[dimension]
                        }}</span
                        ><strong class="metric-value">{{
                            format(dimensionReport.distinct_values)
                        }}</strong>
                    </div>
                    <p class="data-note hidden sm:flex items-center p-5">
                        {{
                            value
                                ? "Filtered by exact value. Chart and CSV use the same selection."
                                : "Only events with this dimension collected are included."
                        }}
                    </p>
                </div>
                <div class="panel-heading !border-0">
                    <h2>
                        {{
                            overview
                                ? "Activity over time"
                                : isSessions
                                  ? "When sessions started"
                                  : {
                                        pages: "Pageviews over time",
                                        sources: "Source traffic",
                                        campaigns: "Campaign activity",
                                        countries: "Country traffic",
                                        events: "Event activity",
                                    }[current]
                        }}
                    </h2>
                    <span class="text-xs text-muted flex items-center gap-2"
                        ><i class="size-1.5 bg-primary rounded-full" />{{
                            labels[metric]
                        }}<span class="text-dimmed hidden sm:inline">{{
                            isDimension
                                ? "Daily"
                                : period.granularity === "hour"
                                  ? "Hourly"
                                  : "Daily"
                        }}</span></span
                    >
                </div>
                <TrafficChart :series="series" :metric="metric" />
                <UCollapsible
                    v-model:open="dataOpen"
                    class="border-t border-default"
                    ><UButton
                        color="neutral"
                        variant="ghost"
                        size="xs"
                        :icon="
                            dataOpen
                                ? 'i-tabler-chevron-down'
                                : 'i-tabler-chevron-right'
                        "
                        label="View chart data"
                        class="m-3" /><template #content
                        ><UTable
                            :data="series"
                            :columns="chartColumns" /></template
                ></UCollapsible>
            </div>
            <div
                v-if="!isSessions"
                :class="overview ? 'grid lg:grid-cols-2 gap-6 mt-6' : 'mt-6'"
            >
                <div class="report-panel">
                    <header class="panel-heading">
                        <h2>
                            <UIcon
                                :name="icons[dimension]"
                                class="size-4 text-muted"
                            />{{ headings[dimension] }}
                        </h2>
                    </header>
                    <form
                        v-if="dimension === 'source' && !overview"
                        class="flex flex-wrap items-end gap-3 p-5 border-b border-default"
                        @submit.prevent="filter"
                    >
                        <UFormField label="Sources"
                            ><USelect
                                v-model="group"
                                :items="[
                                    { label: 'All sources', value: 'all' },
                                    { label: 'AI sources', value: 'ai' },
                                ]"
                                aria-label="Source group"
                                class="w-40" /></UFormField
                        ><UFormField label="Provider"
                            ><USelect
                                v-model="provider"
                                :items="[
                                    { label: 'All providers', value: '' },
                                    ...[
                                        'chatgpt',
                                        'claude',
                                        'perplexity',
                                        'gemini',
                                        'copilot',
                                        'grok',
                                    ].map((value) => ({
                                        label: {
                                            chatgpt: 'ChatGPT',
                                            claude: 'Claude',
                                            perplexity: 'Perplexity',
                                            gemini: 'Gemini',
                                            copilot: 'Copilot',
                                            grok: 'Grok',
                                        }[value],
                                        value,
                                    })),
                                ]"
                                aria-label="Provider"
                                class="w-40" /></UFormField
                        ><UButton
                            type="submit"
                            color="neutral"
                            variant="outline"
                            label="Apply"
                        />
                    </form>
                    <form
                        v-if="!overview"
                        class="flex gap-2 p-5 border-b border-default"
                        @submit.prevent="filter"
                    >
                        <UInput
                            v-model="value"
                            icon="i-tabler-search"
                            placeholder="Filter by exact value"
                            aria-label="Exact value"
                            class="flex-1 min-w-0"
                        /><UButton
                            type="submit"
                            color="neutral"
                            variant="outline"
                            label="Filter"
                        /><UButton
                            v-if="value"
                            label="Clear"
                            color="neutral"
                            variant="ghost"
                            @click="
                                value = '';
                                filter();
                            "
                        />
                    </form>
                    <CampaignTable
                        v-if="dimension === 'campaign' && !overview"
                        :rows="rows"
                        :total="dimensionReport.total"
                        :report-url="page.props.currentUrl"
                        :query="query"
                    />
                    <div
                        v-else-if="dimension === 'event' && !overview"
                        class="event-grid"
                    >
                        <button
                            v-for="row in rows"
                            :key="row.value"
                            class="event-card"
                            type="button"
                            @click="
                                value = row.value;
                                filter();
                            "
                        >
                            <UIcon
                                name="i-tabler-bolt"
                                class="size-5 text-primary"
                            /><span class="text-sm font-medium break-all">{{
                                row.value
                            }}</span
                            ><strong
                                class="text-2xl font-medium tabular-nums"
                                >{{ format(row.count) }}</strong
                            ><span class="text-xs text-muted"
                                >{{
                                    dimensionReport.total
                                        ? (
                                              (row.count /
                                                  dimensionReport.total) *
                                              100
                                          ).toFixed(1)
                                        : 0
                                }}% of events
                                <UIcon name="i-tabler-arrow-up-right"
                            /></span></button
                        ><UEmpty
                            v-if="!rows.length"
                            title="No custom events yet"
                            description="Named actions will appear here when your website sends them."
                            icon="i-tabler-bolt"
                        />
                    </div>
                    <BreakdownTable
                        v-else
                        :rows="rows"
                        :type="dimension"
                        :total="
                            overview
                                ? report.totals.pageviews
                                : dimensionReport.total
                        "
                        :compact="overview"
                        :report-url="
                            overview ? siteLinks.pages : page.props.currentUrl
                        "
                        :query="query"
                    />
                </div>
                <div v-if="overview" class="report-panel">
                    <header class="panel-heading">
                        <h2>
                            <UIcon
                                name="i-tabler-arrow-up-right"
                                class="size-4 text-muted"
                            />Sources
                        </h2>
                    </header>
                    <BreakdownTable
                        :rows="breakdowns.source"
                        type="source"
                        :total="report.totals.pageviews"
                        compact
                        :report-url="siteLinks.sources"
                        :query="query"
                    />
                </div>
            </div>
        </div>
        <UCollapsible v-model:open="notesOpen" class="mt-6"
            ><UButton
                color="neutral"
                variant="ghost"
                icon="i-tabler-info-circle"
                trailing-icon="i-tabler-chevron-down"
                label="About these numbers"
                size="sm"
            /><template #content
                ><div
                    class="data-note p-5 border border-default mt-2 space-y-2"
                >
                    <p>
                        Sessions are not people. Session measurement:
                        {{ report.availability.sessions }}. Unavailable periods
                        are not reconstructed.
                    </p>
                    <p>
                        Source, campaign and device coverage:
                        {{ report.availability.dimensions }}. Country coverage:
                        {{ report.availability.countries }}.
                    </p>
                    <p>
                        UTM-first attribution applies from
                        {{ report.attribution_v2_since || "this version" }}.
                        Earlier Direct traffic cannot be reconstructed.
                    </p>
                    <ul class="list-disc ml-5">
                        <li
                            v-for="entry in report.measurement_periods"
                            :key="entry.started_at"
                        >
                            {{ entry.started_at }} →
                            {{ entry.ended_at || "Present" }} UTC ·
                            {{ entry.mode }}
                        </li>
                    </ul>
                </div></template
            ></UCollapsible
        >
    </template>
</template>
