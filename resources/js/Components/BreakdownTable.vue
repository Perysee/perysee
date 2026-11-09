<script setup>
import ShareValue from "./ShareValue.vue";
import EmptyState from "./EmptyState.vue";
import { computed } from "vue";
import { usePage, router } from "@inertiajs/vue3";
import { queryUrl, selectFilter } from "../interface-query.mjs";
const props = defineProps({
    rows: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    type: String,
    compact: Boolean,
    reportUrl: String,
    query: { type: Object, default: () => ({}) },
});
const page = usePage();
const monoLogos = ["chatgpt", "grok"];
const logoUrl = (logo) => `${page.props.assets.brands}/color/${logo}.svg`;
const columns = [
    {
        accessorKey: "value",
        header:
            {
                path: "Page",
                source: "Source",
                campaign: "Campaign",
                event: "Event",
                country: "Country",
                browser: "Browser family",
                device_brand: "Brand",
                device: "Device type",
            }[props.type] || "Value",
    },
    {
        accessorKey: "count",
        header: props.type === "event" ? "Events" : "Pageviews",
        meta: {
            class: { th: "text-right", td: "text-right whitespace-nowrap" },
        },
    },
    {
        id: "share",
        header: "Share",
        meta: {
            class: { th: "text-right", td: "text-right whitespace-nowrap" },
        },
    },
];
const visible = computed(() =>
    props.compact ? props.rows.slice(0, 7) : props.rows,
);
function name(row) {
    if (props.type === "country" && /^[A-Z]{2}$/.test(row.value)) {
        try {
            return new Intl.DisplayNames(["en"], { type: "region" }).of(
                row.value,
            );
        } catch {}
    }
    if (props.type === "campaign") {
        try {
            const campaign = JSON.parse(row.value);
            return [
                campaign.campaign || "Untitled campaign",
                [campaign.source, campaign.medium].filter(Boolean).join(" / "),
            ]
                .filter(Boolean)
                .join(" · ");
        } catch {}
    }
    return row.label || row.value;
}
const leadingCount = computed(() =>
    Math.max(1, ...props.rows.map((row) => row.count)),
);
const share = (row) =>
    props.total ? Math.min(100, (row.count * 100) / props.total) : 0;
</script>
<template>
    <UTable
        :data="visible"
        :columns="columns"
        :ui="{
            thead: 'border-b border-default [&>tr]:after:hidden',
            th: 'h-9 py-0 px-5 text-xs text-dimmed font-medium',
            td: 'text-sm py-1 px-5 tabular-nums',
            root: 'w-full',
        }"
    >
        <template #empty
            ><EmptyState
                title="No data for this selection"
                description="Adjust the period or filters, or check that the tracking snippet is installed."
        /></template>
        <template #value-cell="{ row }"
            ><component
                :is="reportUrl ? 'a' : 'span'"
                :href="
                    reportUrl
                        ? queryUrl(reportUrl, { ...query, ...selectFilter(page.url, type, row.original.value) })
                        : undefined
                "
                class="breakdown-label"
                :style="{
                    '--bar-width-ratio': row.original.count / leadingCount,
                }"
                :title="reportUrl ? 'Filter: ' + name(row.original) : undefined"
                @click="
                    (event) => {
                        if (!reportUrl) return;
                        event.preventDefault();
                        router.visit(
                            queryUrl(reportUrl, {
                                ...query,
                                ...selectFilter(page.url, type, row.original.value),
                            }),
                            { preserveState: true, preserveScroll: true },
                        );
                    }
                "
                ><span class="bar-content"
                ><img
                    v-if="type === 'source' && row.original.icon"
                    :src="logoUrl(row.original.icon)"
                    alt=""
                    class="source-logo"
                    :class="{ 'source-logo-mono': monoLogos.includes(row.original.icon) }"
                /><UIcon
                    v-else-if="type === 'source'"
                    name="i-tabler-world"
                    class="size-4 text-muted"
                /><img
                    v-else-if="['browser', 'device_brand'].includes(type) && row.original.logo"
                    :src="logoUrl(row.original.logo)"
                    alt=""
                    class="source-logo"
                /><UIcon
                    v-else-if="['browser', 'device_brand'].includes(type)"
                    :name="row.original.icon"
                    class="size-5 text-muted shrink-0"
                /><span>{{ name(row.original) }}</span></span
                ><span class="bar-content is-ink" aria-hidden="true"
                ><img
                    v-if="type === 'source' && row.original.icon"
                    :src="logoUrl(row.original.icon)"
                    alt=""
                    class="source-logo"
                    :class="{ 'source-logo-mono': monoLogos.includes(row.original.icon) }"
                /><UIcon
                    v-else-if="type === 'source'"
                    name="i-tabler-world"
                    class="size-4 text-muted"
                /><img
                    v-else-if="['browser', 'device_brand'].includes(type) && row.original.logo"
                    :src="logoUrl(row.original.logo)"
                    alt=""
                    class="source-logo"
                /><UIcon
                    v-else-if="['browser', 'device_brand'].includes(type)"
                    :name="row.original.icon"
                    class="size-5 text-muted shrink-0"
                /><span>{{ name(row.original) }}</span></span
                ></component
            ></template
        >
        <template #count-cell="{ row }">{{
            new Intl.NumberFormat("en").format(row.original.count)
        }}</template>
        <template #share-cell="{ row }"
            ><ShareValue :count="row.original.count" :total="total"
        /></template>
    </UTable>
    <div
        class="flex justify-between items-center gap-4 px-5 py-2 border-t border-default text-xs text-dimmed"
    >
        <span>{{
            compact
                ? `${visible.length} values`
                : `${visible.length} values shown, as a share of matching ${type === "event" ? "events" : "pageviews"}`
        }}</span
        ><UButton
            v-if="compact && reportUrl"
            :to="queryUrl(reportUrl, query)"
            label="View report"
            size="xs"
            color="primary"
            variant="link"
        />
    </div>
</template>
