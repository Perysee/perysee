<script setup>
import ShareValue from "./ShareValue.vue";
import EmptyState from "./EmptyState.vue";
import { computed } from "vue";
import { queryUrl, selectFilter } from "../interface-query.mjs";
import { Link, usePage } from "@inertiajs/vue3";
const page=usePage();
const props = defineProps(["rows", "total", "reportUrl", "query"]);
const campaigns = computed(() =>
    props.rows.map((row) => {
        let campaign = {};
        try {
            campaign = JSON.parse(row.value) || {};
        } catch {}
        return {
            ...row,
            name: campaign.campaign || "Unnamed campaign",
            source: campaign.source || "Not set",
            medium: campaign.medium || "Not set",
        };
    }),
);
const leadingCount = computed(() =>
    Math.max(1, ...props.rows.map((row) => row.count)),
);
const columns = [
    { accessorKey: "name", header: "Campaign" },
    { accessorKey: "source", header: "Source" },
    { accessorKey: "medium", header: "Medium" },
    {
        accessorKey: "count",
        header: "Pageviews",
        meta: { class: { th: "text-right", td: "text-right" } },
    },
    {
        id: "share",
        header: "Share",
        meta: { class: { th: "text-right", td: "text-right" } },
    },
];
</script>
<template>
    <UTable
        :data="campaigns"
        :columns="columns"
        :ui="{
            thead: 'border-b border-default [&>tr]:after:hidden',
            th: 'h-9 py-0 px-5 text-xs text-dimmed font-medium',
            td: 'text-sm py-1 px-5 tabular-nums',
        }"
        ><template #empty
            ><EmptyState
                title="No campaigns measured"
                description="UTM-tagged pageviews will appear here."
                icon="i-tabler-speakerphone" /></template
        ><template #name-cell="{ row }"
            ><Link
                :href="queryUrl(reportUrl, { ...query, ...selectFilter(page.url, 'campaign', row.original.value) })"
                class="breakdown-label text-sm"
                :style="{ '--bar-width-ratio': row.original.count / leadingCount }"
                ><span class="bar-content"
                    ><UIcon name="i-tabler-speakerphone" class="size-4 shrink-0" /><span>{{ row.original.name }}</span></span
                ><span class="bar-content is-ink" aria-hidden="true"
                    ><UIcon name="i-tabler-speakerphone" class="size-4 shrink-0" /><span>{{ row.original.name }}</span></span
                ></Link
            ></template
        ><template #count-cell="{ row }">{{
            new Intl.NumberFormat("en").format(row.original.count)
        }}</template
        ><template #share-cell="{ row }"
            ><ShareValue :count="row.original.count" :total="total" /></template
    ></UTable>
    <p class="text-xs text-muted p-5 border-t border-default">
        {{ rows.length }} campaigns shown · only tagged pageviews
    </p>
</template>
