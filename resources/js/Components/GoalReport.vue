<script setup>
import {computed,ref} from 'vue';
import {router,usePage} from '@inertiajs/vue3';
import DateRange from './DateRange.vue';
import FilterBar from './FilterBar.vue';
import TrafficChart from './TrafficChart.vue';
import GoalEditor from './GoalEditor.vue';
import ReportHeader from './ReportHeader.vue';
import {queryUrl,reportFilters} from '../interface-query.mjs';
import {formatMoney} from '../event-values.mjs';
const props=defineProps(['website','siteLinks','canUpdate','period','today','goal','definition','report','version','versions','updateUrl','archiveUrl','exportUrl']);
const page=usePage(), editing=ref(false);
const sessions=computed(()=>props.report.mode==='sessions');
const metric=computed(()=>sessions.value?'conversions':'hits');
const percent=value=>value===null||value===undefined?'Unavailable':value+' %';
const count=value=>value===null||value===undefined?'Unavailable':value;
const groups=[{key:'source',title:'Source'},{key:'campaign',title:'Campaign'},{key:'country',title:'Country'},{key:'device',title:'Device'}];
// The value of the hits appears only once a hit of the period carried one; it is a label in the site currency, never converted.
const valued=computed(()=>(props.report.amounts?.valued_hits||0)>0);
const money=value=>formatMoney(value,props.report.amounts?.currency);
const columns=title=>[{accessorKey:'label',header:title},{accessorKey:sessions.value?'conversions':'hits',header:sessions.value?'Conversions':'Goal hits'},...(sessions.value?[{accessorKey:'sessions',header:'Sessions'},{accessorKey:'rate',header:'Rate'}]:[]),...(valued.value?[{accessorKey:'amount',header:'Value'},{accessorKey:'average',header:'Average'}]:[])];
const cells=rows=>rows.map(row=>({...row,conversions:count(row.conversions),sessions:count(row.sessions),rate:percent(row.rate),amount:row.valued_hits?money(row.amount):'—',average:row.average===null?'—':money(row.average)}));
const navigate=extras=>router.get(queryUrl(page.props.currentUrl,{...reportFilters(page.url),from:props.period.from,to:props.period.to,version:props.version,...extras}));
const archive=()=>{if(confirm('Archive this goal? Its figures remain available.'))router.delete(props.archiveUrl);};
</script>
<template>
<ReportHeader :title="goal.name" :demo="website.is_demo" :meta="[website.domain,(definition.type==='event'?'Event ':'Page ')+definition.value,definition.threshold>1?'Reached on hit '+definition.threshold:null]"><UButton v-if="canUpdate && !goal.archived_at" label="Edit goal" color="neutral" variant="outline" size="sm" @click="editing=!editing"/><UButton v-if="canUpdate && !goal.archived_at" label="Archive" color="neutral" variant="ghost" size="sm" @click="archive"/><UButton :href="exportUrl" external label="Export CSV" icon="i-tabler-download" color="neutral" variant="outline" size="sm"/></ReportHeader>
<UAlert v-if="!sessions" color="warning" title="Session measurement required" description="Conversions and the conversion rate are unavailable in pageview mode. Only the hits on the goal are counted." class="mb-5"/>
<UCard v-if="editing" class="mb-5"><GoalEditor :initial="{...definition,name:goal.name}" :url="updateUrl" :editing="true" @saved="editing=false"/></UCard>
<div class="flex flex-wrap justify-between gap-4 items-center mb-4"><DateRange :period="period" :today="today" :timezone="website.timezone"/><USelect :model-value="version" :items="versions.map(value=>({value,label:'Version '+value}))" aria-label="Goal version" @update:model-value="value=>navigate({version:value})"/></div>
<FilterBar :period="period" :site-links="siteLinks"/>
<p class="text-sm mb-5" :class="report.coverage.status==='available'?'text-muted':'text-warning'" role="status">Coverage: {{report.coverage.status}}. {{report.coverage.message}}</p>
<div class="grid sm:grid-cols-3 gap-4 mb-5" :class="valued?'xl:grid-cols-5':''"><UCard><span class="metric-label">{{sessions?'Conversions':'Goal hits'}}</span><strong class="metric-value">{{sessions?count(report.totals.conversions):report.totals.hits}}</strong></UCard><UCard><span class="metric-label">Sessions</span><strong class="metric-value">{{count(report.totals.sessions)}}</strong></UCard><UCard><span class="metric-label">Conversion rate</span><strong class="metric-value">{{percent(report.totals.rate)}}</strong></UCard><template v-if="valued"><UCard><span class="metric-label">Value</span><strong class="metric-value">{{money(report.amounts.total)}}</strong></UCard><UCard><span class="metric-label">Average value</span><strong class="metric-value">{{money(report.amounts.average)}}</strong></UCard></template></div>
<p v-if="valued" class="text-xs text-muted -mt-3 mb-5">Value adds up every hit that carried one, so a session that orders twice counts two orders but one conversion. Amounts are as sent by your site, in {{report.amounts.currency||'the currency of your site'}}, not converted and not an accounting record.</p>
<div class="grid 2xl:grid-cols-2 gap-5"><UCard v-for="group in groups" :key="group.key" :ui="{body:'p-0 sm:p-0'}"><template #header><h2 class="text-sm font-medium">By {{group.title.toLowerCase()}} (first touch)</h2></template><UTable v-if="report.breakdown[group.key].length" :data="cells(report.breakdown[group.key])" :columns="columns(group.title)"/><p v-else class="p-4 text-sm text-muted">No {{sessions?'conversion':'hit'}} with a known {{group.title.toLowerCase()}} in this selection.</p></UCard></div>
<UCard class="mt-5"><template #header><h2 class="text-sm font-medium">{{sessions?'Conversions':'Goal hits'}} over time</h2></template><TrafficChart :series="report.series" :metric="metric"/></UCard>
</template>
