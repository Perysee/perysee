<script setup>
import { computed } from "vue";
import { usePage, router } from "@inertiajs/vue3";
import TrafficChart from "./TrafficChart.vue";
import DateRange from "./DateRange.vue";
import { queryUrl } from "../interface-query.mjs";
import { describeComparison } from "../comparison.mjs";
const props = defineProps(["sites", "summaries", "charts", "period", "today", "sitePeriods", "comparisons", "attention"]);
// With three sites or more, the busiest one leads the grid at full width.
const featuredId = computed(() => {
    if (props.sites.data.length < 3) return null;
    const ranked = props.sites.data
        .map((site) => [site.id, props.summaries[site.id].totals.pageviews ?? 0])
        .sort((a, b) => b[1] - a[1]);
    return ranked[0][1] > ranked[1][1] ? ranked[0][0] : null;
});
// A site that was never measured, as opposed to one that is quiet in this period. Demonstration sites carry synthetic data.
const waiting = (site) => !site.first_collected_at && !site.is_demo;
// Totals of the sites on screen. With several pages they would be misleading, so they are shown for a single page only.
const totals = computed(() =>
    ["pageviews", "custom_events"].reduce(
        (sum, key) => ({ ...sum, [key]: props.sites.data.reduce((total, site) => total + (props.summaries[site.id]?.totals[key] ?? 0), 0) }),
        {},
    ),
);
const quiet = (site) =>
    Object.values(props.summaries[site.id].totals).every((total) => !total);
const variationOf = (site, key) => {
    const comparison = props.comparisons?.[site.id];
    if (!comparison) return null;
    return describeComparison(
        props.summaries[site.id].totals[key],
        comparison.totals?.[key] ?? null,
        comparison.reliability?.[key],
    );
};
const page = usePage();
const number = (value) =>
    value === null ? "—" : new Intl.NumberFormat("en").format(value);
</script>
<template>
    <div class="page-heading">
        <div>
            <h1>Home</h1>
            <p class="text-sm text-muted">
                Consolidated activity, each site in its own timezone
            </p>
        </div>
        <UButton
            :to="page.props.links['sites.create']"
            icon="i-tabler-plus"
            label="Add site"
        />
    </div>
    <UAlert
        v-for="item in attention ?? []"
        :key="item.code"
        color="warning"
        variant="soft"
        icon="i-tabler-alert-triangle"
        title="Something needs your attention"
        :description="item.message"
        :actions="[{ label: 'Instance health', to: item.link, color: 'neutral', variant: 'outline', size: 'xs' }]"
        class="mb-6"
    />
    <div class="report-toolbar">
        <p class="text-xs text-muted">
            {{ sites.total }} {{ sites.total === 1 ? "site" : "sites"
            }}<template v-if="sites.data.length && sites.last_page === 1">
                · <strong class="text-default font-medium">{{ number(totals.pageviews) }}</strong> pageviews ·
                <strong class="text-default font-medium">{{ number(totals.custom_events) }}</strong> custom events</template
            >
        </p>
        <DateRange :period="period" :today="today" />
    </div>
    <div class="grid xl:grid-cols-2 xl:grid-flow-dense gap-6">
        <article
            v-for="site in sites.data"
            :key="site.id"
            class="report-panel site-card"
            :class="{ 'is-featured': site.id === featuredId }"
        >
            <header class="site-header">
                <div class="min-w-0">
                    <UButton
                        :to="
                            queryUrl(site.url, {
                                from: sitePeriods[site.id].from,
                                to: sitePeriods[site.id].to,
                            })
                        "
                        :label="site.name"
                        color="neutral"
                        variant="link"
                        class="p-0 font-medium"
                    />
                    <p class="text-xs text-muted mt-1">
                        {{ site.domain
                        }}<template v-if="site.is_demo"> · Demonstration data</template>
                    </p>
                </div>
                <span
                    class="site-status"
                    :class="
                        summaries[site.id].freshness.pending
                            ? 'is-pending'
                            : 'is-consolidated'
                    "
                    >{{
                        summaries[site.id].freshness.pending
                            ? "Pending"
                            : "Consolidated"
                    }}</span
                >
            </header>
            <div class="metric-grid">
                <div
                    v-for="[key, label] in [
                        ['pageviews', 'Pageviews'],
                        ['sessions', 'Sessions'],
                        ['custom_events', 'Custom events'],
                    ]"
                    :key="key"
                    class="metric-cell"
                >
                    <span class="metric-label">{{ label }}</span
                    ><strong class="metric-value">{{
                        number(summaries[site.id].totals[key])
                    }}</strong
                    ><span
                        v-if="variationOf(site, key)?.percent"
                        class="metric-variation"
                        :class="`is-${variationOf(site, key).direction}`"
                        >{{ variationOf(site, key).percent
                        }}<span class="metric-versus"> vs previous</span
                        ><span class="sr-only">
                            {{ variationOf(site, key).label }}</span
                        ></span
                    ><span
                        v-else-if="variationOf(site, key)?.reasonText"
                        class="metric-reason"
                        >Previous: {{ variationOf(site, key).previousText }}.
                        {{ variationOf(site, key).reasonText }}</span
                    ><span
                        v-else-if="
                            key === 'sessions' &&
                            summaries[site.id].availability.sessions ===
                                'unavailable'
                        "
                        class="metric-caption"
                        >Unavailable</span
                    >
                </div>
            </div>
            <div v-if="waiting(site)" class="site-trend is-quiet">
                <div class="flex flex-col items-start gap-2 px-6 py-5">
                    <p class="text-sm font-medium">Waiting for the first event</p>
                    <p class="text-xs text-muted">
                        Add the Lueur script to your website to start measuring.
                    </p>
                    <UButton :to="site.tracking_url" label="Set up tracking" size="xs" />
                </div>
            </div>
            <div v-else-if="quiet(site)" class="site-trend is-quiet">
                <p class="text-xs text-muted">No traffic in this period</p>
            </div>
            <div v-else class="site-trend">
                <TrafficChart :series="charts[site.id]" compact />
            </div>
            <footer
                class="site-footer"
            >
                <span class="text-xs text-muted tabular-nums"
                    >{{ sitePeriods[site.id].from }} –
                    {{ sitePeriods[site.id].to }} ·
                    {{ site.timezone }}</span
                ><UButton
                    :to="
                        queryUrl(site.url, { from: sitePeriods[site.id].from, to: sitePeriods[site.id].to })
                    "
                    label="Open report"
                    trailing-icon="i-tabler-arrow-up-right"
                    color="neutral"
                    variant="link"
                    size="xs"
                    class="shrink-0 px-0"
                />
            </footer>
        </article>
    </div>
    <UEmpty
        v-if="!sites.data.length"
        title="Your first insight starts with a site"
        description="Add your website and connect Lueur."
        icon="i-tabler-chart-line"
        :actions="[
            {
                label: 'Add your first site',
                to: page.props.links['sites.create'],
            },
        ]"
    />
    <div v-if="sites.last_page > 1" class="flex justify-center mt-6">
        <UPagination
            :page="sites.current_page"
            :total="sites.total"
            :items-per-page="sites.per_page"
            @update:page="
                (number) =>
                    router.get(page.props.links.dashboard, {
                        from: period.from,
                        to: period.to,
                        page: number,
                    })
            "
        />
    </div>
</template>
