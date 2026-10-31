<script setup>
import { usePage } from "@inertiajs/vue3";
defineProps({
    title: String,
    description: String,
    icon: { type: String, default: "i-tabler-chart-bar-off" },
});
const page = usePage();
</script>
<template>
    <UEmpty
        :title="title"
        :description="description"
        :icon="icon"
        :actions="
            page.props.siteLinks?.tracking
                ? [
                      {
                          label: 'Tracking setup',
                          to: page.props.siteLinks.tracking,
                          color: 'neutral',
                          variant: 'outline',
                          size: 'sm',
                      },
                  ]
                : []
        "
        class="py-10"
    />
</template>
