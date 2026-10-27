<script setup>
import {computed,ref,watch,onBeforeUnmount} from 'vue';
import {router,usePage} from '@inertiajs/vue3';
import DateRange from './DateRange.vue';
import FilterBar from './FilterBar.vue';
import SankeyChart from './SankeyChart.vue';
import FunnelEditor from './FunnelEditor.vue';
import {queryUrl,reportFilters} from '../interface-query.mjs';
const props=defineProps(['website','siteLinks','canUpdate','period','today','funnel','steps','version','versions','start','job','reportLinks','updateUrl','archiveUrl','calculateUrl']);
const page=usePage(), status=ref(props.job), editing=ref(false), pollError=ref(false), startType=ref(props.start?.type || 'path'), startValue=ref(props.start?.value || '/');
const data=computed(()=>status.value?.data), isFunnel=computed(()=>!!props.funnel);
const labels=computed(()=>Object.fromEntries((data.value?.nodes || []).map(node=>[node.name,node.label])));
const transitions=computed(()=>(data.value?.links || []).map(link=>({source:labels.value[link.source],target:labels.value[link.target],sessions:link.value})));
let timer, controller, disposed=false;
async function poll(){
 if(disposed || !status.value || ['ready','error'].includes(status.value.status))return;
 controller?.abort();controller=new AbortController();
 try{const response=await fetch(props.reportLinks.status,{signal:controller.signal,headers:{Accept:'application/json'}});if(!response.ok)throw Error();status.value=await response.json();pollError.value=false;}
 catch(e){if(e.name!=='AbortError')pollError.value=true;}
 if(!disposed)timer=setTimeout(poll,5000);
}
watch(()=>props.job,job=>{status.value=job;clearTimeout(timer);poll();},{immediate:true});
onBeforeUnmount(()=>{disposed=true;clearTimeout(timer);controller?.abort();});
const navigate=extras=>router.get(queryUrl(page.props.currentUrl,{...reportFilters(page.url),from:props.period.from,to:props.period.to,version:props.version,start_type:startType.value,start_value:startValue.value,...extras}));
const archive=()=>{if(confirm('Archive this funnel? Its definitions remain available.'))router.delete(props.archiveUrl);};
</script>
<template>
<UBadge v-if="website.is_demo" color="warning" variant="subtle" class="mb-4">Demonstration data · synthetic events</UBadge>
<div class="page-heading !flex-col lg:!flex-row"><div><p class="eyebrow">{{website.domain}}</p><h1>{{isFunnel?funnel.name:'Journeys'}}</h1><p class="text-sm text-muted">{{isFunnel?'Ordered steps · intermediate actions allowed':'Explore actions after an entry point'}} · one measured session</p></div><div class="flex flex-wrap gap-2 shrink-0"><UButton v-if="job" label="Refresh report" icon="i-tabler-refresh" color="neutral" variant="ghost" @click="router.post(calculateUrl,{...reportFilters(page.url),from:period.from,to:period.to,version,start_type:startType,start_value:startValue})"/><UButton v-if="isFunnel && canUpdate && !funnel.archived_at" label="Edit funnel" color="neutral" variant="outline" @click="editing=!editing"/><UButton v-if="isFunnel && canUpdate && !funnel.archived_at" label="Archive" color="neutral" variant="ghost" @click="archive"/><UButton v-if="status?.status==='ready'" :href="reportLinks.export" external label="Export CSV" icon="i-tabler-download" color="neutral" variant="outline"/></div></div>
<UAlert v-if="website.tracking_mode!=='sessions'" color="warning" title="Session measurement required" description="Actions cannot be connected in identifier-free pageview mode. Session measurement remains subject to your consent configuration." class="mb-5"/>
<UButton v-if="website.tracking_mode!=='sessions' && canUpdate" :to="siteLinks.edit" label="Site settings" class="mb-5"/>
<UCard v-if="editing" class="mb-5"><FunnelEditor :initial="funnel" :steps="steps" :url="updateUrl" editing @saved="editing=false"/></UCard>
<div class="flex flex-wrap justify-between gap-4 items-center mb-4"><DateRange :period="period" :today="today" :timezone="website.timezone"/><USelect v-if="isFunnel" :model-value="version" :items="versions.map(value=>({value,label:'Version '+value}))" aria-label="Funnel version" @update:model-value="value=>navigate({version:value})"/></div>
<form v-if="!isFunnel" class="flex flex-wrap items-end gap-3 mb-5" @submit.prevent="navigate({})"><UFormField label="Starting action"><USelect v-model="startType" :items="[{value:'path',label:'Page path'},{value:'event',label:'Named event'}]"/></UFormField><UFormField label="Exact value"><UInput v-model="startValue" required maxlength="512"/></UFormField><UButton type="submit" label="Explore" icon="i-tabler-route"/></form>
<FilterBar :period="period" :site-links="siteLinks" journey/>
<p class="text-xs text-muted mb-5">Filters match the entry action; intermediate actions remain in the sequence. Sessions are counted once. Reports use retained raw events and may include steps completed after the selected end date.</p>
<UAlert v-if="pollError" title="Status unavailable" description="Retrying automatically. The calculation continues on the scheduler." color="warning" class="mb-5"/>
<UAlert v-if="status && !['ready','error'].includes(status.status)" color="neutral" title="Calculating session flows" :description="`${status.scanned_events} events scanned · ${status.status}. The scheduler processes small resumable batches. Check Instance health if this does not advance.`" class="mb-5"/>
<UAlert v-if="status?.status==='error'" title="Calculation failed" :description="status.error" color="error" class="mb-5"/>
<template v-if="data"><UAlert v-if="data.coverage.status!=='available'" title="Partial coverage" :description="data.coverage.message+' Excluded sessions: '+data.coverage.excluded_sessions" color="warning" class="mb-5"/><div class="grid sm:grid-cols-3 gap-4 mb-5"><UCard><span class="metric-label">Entered</span><strong class="metric-value">{{data.entries}}</strong></UCard><UCard v-if="isFunnel"><span class="metric-label">Converted</span><strong class="metric-value">{{data.conversions}}</strong></UCard><UCard v-if="isFunnel"><span class="metric-label">Conversion · closed outcomes</span><strong class="metric-value">{{data.conversion_rate===null?'—':data.conversion_rate+'%'}}</strong><p class="text-xs text-muted">{{data.pending}} sessions still in progress</p></UCard></div>
<SankeyChart v-if="data.entries" :report="data"/><UEmpty v-else title="No complete session data for this selection" description="Check the entry action, filters, session mode and retained history." icon="i-tabler-route"/>
<UCard class="mt-5" :ui="{body:'p-0 sm:p-0'}"><template #header><h2 class="text-sm font-medium">{{isFunnel?'Step results':'Measured transitions'}}</h2></template><UTable v-if="isFunnel" :data="data.steps" :columns="[{accessorKey:'label',header:'Step'},{accessorKey:'count',header:'Sessions'},{accessorKey:'rate',header:'Progression %'},{accessorKey:'abandoned',header:'Abandoned'},{accessorKey:'pending',header:'In progress'}]"/><UTable v-else :data="transitions" :columns="[{accessorKey:'source',header:'From'},{accessorKey:'target',header:'To'},{accessorKey:'sessions',header:'Sessions'}]"/></UCard><p class="text-xs text-muted mt-4">Updated {{status.updated_at}} UTC · {{data.coverage.message}}</p></template>
</template>
