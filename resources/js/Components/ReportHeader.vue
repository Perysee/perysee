<script setup>
import { computed } from "vue";

// One row with the title and every control of the report, then the facts about the data as separate items.
const props = defineProps({
    title: { type: String, required: true },
    demo: { type: Boolean, default: false },
    meta: { type: Array, default: () => [] },
});
const items = computed(() => props.meta.filter(Boolean));
</script>
<template>
    <header class="report-head">
        <div class="report-head-row">
            <h1>{{ title }}</h1>
            <div class="report-head-actions"><slot /></div>
        </div>
        <p class="report-head-meta">
            <UBadge
                v-if="demo"
                label="Demonstration data"
                color="warning"
                variant="subtle"
                size="sm"
                title="Synthetic events, not real visits"
            />
            <span v-for="item in items" :key="item">{{ item }}</span>
            <slot name="meta" />
        </p>
        <slot name="below" />
    </header>
</template>
