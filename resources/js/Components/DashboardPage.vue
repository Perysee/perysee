<script setup>
import { usePage, router } from "@inertiajs/vue3";
import TrafficChart from "./TrafficChart.vue";
import DateRange from "./DateRange.vue";
import { queryUrl } from "../interface-query.mjs";
defineProps(["sites", "summaries", "charts", "period", "today", "sitePeriods"]);
const page = usePage();
const number = (value) =>
    value === null ? "—" : new Intl.NumberFormat("en").format(value);
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">Your workspace</p>
            <h1>Dashboard</h1>
            <p class="text-sm text-muted">
                Consolidated activity · each site follows its own reporting
                timezone.
            </p>
        </div>
        <UButton
            :to="page.props.links['sites.create']"
            icon="i-tabler-plus"
            label="Add site"
        />
    </div>
    <div class="report-toolbar">
        <p class="text-xs text-muted">{{ sites.total }} websites</p>
        <DateRange :period="period" :today="today" />
    </div>
    <div class="grid xl:grid-cols-2 gap-6">
        <article v-for="site in sites.data" :key="site.id" class="report-panel">
            <header
                class="p-5 flex items-start justify-between border-b border-default"
            >
                <div>
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
                    <p class="text-xs text-muted mt-1">{{ site.domain }}</p><p class="text-xs text-muted mt-1">{{ sitePeriods[site.id].from }} – {{ sitePeriods[site.id].to }} · {{ site.timezone }}</p>
                    <UBadge
                        v-if="site.is_demo"
                        color="warning"
                        variant="subtle"
                        size="xs"
                        class="mt-2"
                        >Demonstration data</UBadge
                    >
                </div>
                <UBadge
                    :color="
                        summaries[site.id].freshness.pending
                            ? 'warning'
                            : 'success'
                    "
                    variant="subtle"
                    size="xs"
                    >{{
                        summaries[site.id].freshness.pending
                            ? "Pending"
                            : "Consolidated"
                    }}</UBadge
                >
            </header>
            <div class="metric-grid">
                <div
                    v-for="[key, label] in [
                        ['pageviews', 'Pageviews'],
                        ['sessions', 'Sessions started'],
                        ['custom_events', 'Custom events'],
                    ]"
                    :key="key"
                    class="metric-cell"
                >
                    <span class="metric-label">{{ label }}</span
                    ><strong class="metric-value !text-2xl">{{
                        number(summaries[site.id].totals[key])
                    }}</strong>
                </div>
            </div>
            <TrafficChart :series="charts[site.id]" compact />
            <footer
                class="px-5 py-3 border-t border-default flex items-center justify-between"
            >
                <span class="text-xs text-muted"
                    >Sessions
                    {{ summaries[site.id].availability.sessions }}</span
                ><UButton
                    :to="
                        queryUrl(site.url, { from: sitePeriods[site.id].from, to: sitePeriods[site.id].to })
                    "
                    label="Open report"
                    trailing-icon="i-tabler-arrow-up-right"
                    color="neutral"
                    variant="link"
                    size="xs"
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
