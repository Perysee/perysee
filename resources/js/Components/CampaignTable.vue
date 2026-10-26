<script setup>
import ShareValue from "./ShareValue.vue";
import { computed } from "vue";
import { queryUrl } from "../interface-query.mjs";
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
        empty="No campaigns measured. UTM-tagged pageviews will appear here."
        :ui="{
            th: 'text-xs text-muted font-normal',
            td: 'text-sm py-4 tabular-nums',
        }"
        ><template #name-cell="{ row }"
            ><UButton
                :to="
                    queryUrl(reportUrl, { ...query, value: row.original.value })
                "
                :label="row.original.name"
                icon="i-tabler-speakerphone"
                variant="link"
                color="neutral"
                class="breakdown-label w-full justify-start text-sm text-left"
                :style="{
                    '--bar-width':
                        (row.original.count / leadingCount) * 100 + '%',
                }" /></template
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
