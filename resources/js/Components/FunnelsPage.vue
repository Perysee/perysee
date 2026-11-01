<script setup>
import {ref} from 'vue';
import {usePage} from '@inertiajs/vue3';
import {queryUrl,reportFilters} from '../interface-query.mjs';
const page=usePage();
import FunnelEditor from './FunnelEditor.vue';
import ReportHeader from './ReportHeader.vue';
defineProps(['website','funnels','canUpdate','createUrl','siteLinks','period']);
const creating=ref(false);
</script>
<template>
<ReportHeader title="Funnels"><template #meta>{{website.domain}}<template v-if="website.is_demo"><span class="text-dimmed"> · </span><span class="report-demo" title="Demonstration data · synthetic events">Demonstration data</span></template><span class="text-dimmed"> · </span>Progress through pages and actions within a session</template><UButton v-if="canUpdate" label="Create funnel" icon="i-tabler-plus" size="sm" @click="creating=!creating"/></ReportHeader>
<UAlert v-if="website.tracking_mode!=='sessions'" color="warning" title="Session measurement required" description="Pageviews without an identifier cannot connect actions into a journey. Enable sessions after assessing consent requirements." class="mb-5"/>
<UCard v-if="creating && canUpdate" class="mb-6"><FunnelEditor :url="createUrl" @saved="creating=false"/></UCard>
<div class="grid lg:grid-cols-2 gap-5"><UCard v-for="funnel in funnels" :key="funnel.id"><div class="flex justify-between gap-4 items-center"><div><h2 class="text-base font-medium">{{funnel.name}}</h2><p class="text-xs text-muted mt-2">Version {{funnel.current_version}} · {{funnel.archived_at?'Archived':'Active'}}</p></div><UButton :to="queryUrl(funnel.url,{...reportFilters(page.url),from:period.from,to:period.to})" label="Open funnel" trailing-icon="i-tabler-arrow-right" color="neutral" variant="outline"/></div></UCard></div>
<UEmpty v-if="!funnels.length && !creating" title="Your first funnel" description="Start with a landing page, a pricing page and a successful signup event." icon="i-tabler-filter"/>
</template>
