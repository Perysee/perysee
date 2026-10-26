<script setup>
import { computed } from "vue";
const props = defineProps({ count: Number, total: Number });
const share = computed(() =>
    props.total > 0
        ? Math.max(0, Math.min(100, (props.count * 100) / props.total))
        : 0,
);
</script>
<template>
    <span class="share-value"
        >{{ share > 0 && share < 0.1 ? "<0.1" : share.toFixed(1) }}%</span
    >
</template>
