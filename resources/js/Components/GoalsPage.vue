<script setup>
import {ref} from 'vue';
import {usePage} from '@inertiajs/vue3';
import {queryUrl,reportFilters} from '../interface-query.mjs';
const page=usePage();
import GoalEditor from './GoalEditor.vue';
import ReportHeader from './ReportHeader.vue';
const props=defineProps(['website','goals','limit','canUpdate','createUrl','siteLinks','period']);
const creating=ref(false);
const active=()=>props.goals.filter(goal=>!goal.archived_at).length;
</script>
<template>
<ReportHeader title="Goals" :demo="website.is_demo" :meta="[website.domain,'Sessions that reach a page or an event']"><UButton v-if="canUpdate" label="Create goal" icon="i-tabler-plus" size="sm" :disabled="active()>=limit" @click="creating=!creating"/></ReportHeader>
<UAlert v-if="website.tracking_mode!=='sessions'" color="warning" title="Session measurement required" description="Without sessions only the hits on a goal are counted. Conversions and the conversion rate need session measurement, subject to your consent configuration." class="mb-5"/>
<p v-if="canUpdate" class="text-xs text-muted mb-4">{{active()}} of {{limit}} active goals.</p>
<UCard v-if="creating && canUpdate" class="mb-6"><GoalEditor :url="createUrl" @saved="creating=false"/></UCard>
<div class="grid lg:grid-cols-2 gap-5"><UCard v-for="goal in goals" :key="goal.id"><div class="flex justify-between gap-4 items-center"><div><h2 class="text-base font-medium">{{goal.name}}</h2><p class="text-xs text-muted mt-2">{{goal.type==='event'?'Event':'Page'}} {{goal.value}}<template v-if="goal.threshold>1"> · on hit {{goal.threshold}}</template> · Version {{goal.current_version}} · {{goal.archived_at?'Archived':'Active'}}</p></div><UButton :to="queryUrl(goal.url,{...reportFilters(page.url),from:period.from,to:period.to})" label="Open goal" trailing-icon="i-tabler-arrow-right" color="neutral" variant="outline"/></div></UCard></div>
<UEmpty v-if="!goals.length && !creating" title="Your first goal" description="Pick a thank-you page or a signup event to see how many sessions reach it and where they come from." icon="i-tabler-target"/>
</template>
