<script setup>
import ShareValue from "./ShareValue.vue";
import { computed } from "vue";
import { usePage, router } from "@inertiajs/vue3";
import { queryUrl } from "../interface-query.mjs";
const props = defineProps({
    rows: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    type: String,
    compact: Boolean,
    reportUrl: String,
    query: { type: Object, default: () => ({}) },
});
const page = usePage();
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
        empty="No data for this selection"
        :ui="{
            th: 'text-xs text-muted font-medium',
            td: 'text-sm py-3 tabular-nums',
            root: 'w-full',
        }"
    >
        <template #value-cell="{ row }"
            ><a
                :href="
                    queryUrl(reportUrl, { ...query, value: row.original.value })
                "
                class="breakdown-label"
                :style="{
                    '--bar-width':
                        (row.original.count / leadingCount) * 100 + '%',
                }"
                :title="'Filter: ' + name(row.original)"
                @click.prevent="
                    router.visit(
                        queryUrl(reportUrl, {
                            ...query,
                            value: row.original.value,
                        }),
                        { preserveState: true, preserveScroll: true },
                    )
                "
                ><img
                    v-if="type === 'source' && row.original.icon"
                    :src="
                        page.props.assets.brands +
                        '/' +
                        row.original.icon +
                        '.svg'
                    "
                    alt=""
                    class="source-logo"
                /><UIcon
                    v-else-if="type === 'source'"
                    name="i-tabler-world"
                    class="size-4 text-muted"
                /><UIcon
                    v-if="['browser', 'device_brand'].includes(type)"
                    :name="row.original.icon"
                    class="size-5 text-muted shrink-0"
                /><span>{{ name(row.original) }}</span></a
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
        class="flex justify-between items-center px-5 py-3 border-t border-default text-xs text-muted"
    >
        <span>{{
            compact
                ? `${visible.length} values`
                : `${visible.length} values shown · share of matching ${type === "event" ? "events" : "pageviews"}`
        }}</span
        ><UButton
            v-if="compact"
            :to="queryUrl(reportUrl, query)"
            label="View report"
            trailing-icon="i-tabler-arrow-up-right"
            size="xs"
            color="neutral"
            variant="link"
        />
    </div>
</template>
