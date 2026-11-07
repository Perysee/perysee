<script setup>
import {computed} from 'vue';
import {router,usePage} from '@inertiajs/vue3';
import DateRange from './DateRange.vue';
import ReportHeader from './ReportHeader.vue';
import {queryUrl} from '../interface-query.mjs';
import {formatMoney} from '../event-values.mjs';
const props=defineProps(['website','siteLinks','period','today','report','exportUrl']);
const page=usePage();
const events=computed(()=>props.report.events.map(item=>({value:item.name,label:item.name})));
const hasValues=computed(()=>props.report.keys.some(key=>key.values.some(row=>row.valued>0)));
const money=value=>formatMoney(value,props.report.currency);
const columns=computed(()=>[{accessorKey:'value',header:'Value'},{accessorKey:'count',header:'Events'},...(hasValues.value?[{accessorKey:'amount',header:'Value total'},{accessorKey:'average',header:'Average'}]:[])]);
const cells=rows=>rows.map(row=>({...row,value:row.value==='(other)'?'Other values':row.value,amount:row.valued?money(row.amount):'—',average:row.average===null?'—':money(row.average)}));
const keyTitle=key=>key==='(other)'?'Other properties':key;
const navigate=extras=>router.get(queryUrl(page.props.currentUrl,{from:props.period.from,to:props.period.to,event:props.report.event,...extras}));
</script>
<template>
<ReportHeader title="Event properties"><template #meta>{{website.domain}}<template v-if="website.is_demo"><span class="text-dimmed"> · </span><span class="report-demo" title="Demonstration data · synthetic events">Demonstration data</span></template><span class="text-dimmed"> · </span>Context attributes sent with an event</template><UButton :to="queryUrl(siteLinks.events,{from:period.from,to:period.to})" label="Events" icon="i-tabler-arrow-left" color="neutral" variant="outline" size="sm"/><UButton v-if="report.event" :href="exportUrl" external label="Export CSV" icon="i-tabler-download" color="neutral" variant="outline" size="sm"/></ReportHeader>
<div class="flex flex-wrap justify-between gap-4 items-center mb-4"><DateRange :period="period" :today="today" :timezone="website.timezone"/><USelect v-if="events.length" :model-value="report.event" :items="events" aria-label="Event" class="min-w-48" @update:model-value="value=>navigate({event:value})"/></div>
<UEmpty v-if="!report.event" title="No properties in this period" description="Send up to five short attributes with an event, for example lueur.track('purchase', { props: { payment: 'card' } }). They are counted per day, never per visitor." icon="i-tabler-list-details"/>
<template v-else>
<p class="text-sm text-muted mb-5">Counts of events by property value. A value is never tied to a session or a visitor; each key lists its {{report.limits.values}} most frequent values and sums the rest as other values.</p>
<div class="grid 2xl:grid-cols-2 gap-5"><UCard v-for="key in report.keys" :key="key.key" :ui="{body:'p-0 sm:p-0'}"><template #header><h2 class="text-sm font-medium"><code>{{keyTitle(key.key)}}</code> <span class="text-muted font-normal">· {{key.count}} {{key.count===1?'event':'events'}}<template v-if="hasValues && key.value"> · {{money(key.value)}}</template></span></h2></template><UTable :data="cells(key.values)" :columns="columns"/></UCard></div>
</template>
</template>
