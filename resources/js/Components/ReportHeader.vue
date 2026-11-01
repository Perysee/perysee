<script setup>
defineProps({ title: { type: String, required: true } });
</script>
<template>
    <header class="report-head">
        <div class="min-w-0">
            <h1>{{ title }}</h1>
            <p class="report-head-meta"><slot name="meta" /></p>
        </div>
        <div class="report-head-actions"><slot /></div>
    </header>
</template>
