<script setup>
import {computed,ref,watch,onBeforeUnmount} from 'vue';
import {router,usePage} from '@inertiajs/vue3';
import {queryUrl,reportFilters} from '../interface-query.mjs';
const props=defineProps(['period','siteLinks','journey','activeDimension']);
const page=usePage(), open=ref(false), dimension=ref('country'), search=ref(''), options=ref([]), selected=ref([]), loading=ref(false), error=ref('');
const labels={path:'Page',country:'Country',source:'Source',provider:'AI provider',campaign:'Campaign',device:'Device',device_brand:'Brand',browser:'Browser',event:'Event'};
const dimensions=computed(()=>Object.entries(labels).filter(([key])=>!props.journey || !['path','event'].includes(key)).map(([value,label])=>({value,label})));
const active=computed(()=>{
 const parsed=reportFilters(page.url), filters={...parsed.filters};
 if(parsed.value && props.activeDimension && !filters[props.activeDimension])filters[props.activeDimension]=[parsed.value];
 if(parsed.provider && !filters.provider)filters.provider=[parsed.provider];
 else if(parsed.source_group==='ai' && !filters.provider)filters.provider=['chatgpt','claude','perplexity','gemini','copilot','grok'];
 return filters;
});
const display=(key,value)=>{if(key==='country' && /^[A-Z]{2}$/.test(value)){try{return new Intl.DisplayNames(['en'],{type:'region'}).of(value)+' ('+value+')';}catch{}}if(key==='campaign'){try{const c=JSON.parse(value);return [c.campaign,c.source,c.medium].filter(Boolean).join(' / ');}catch{}}return key==='provider'?({chatgpt:'ChatGPT',claude:'Claude',perplexity:'Perplexity',gemini:'Gemini',copilot:'Copilot',grok:'Grok'}[value] || value):value;};
const chips=computed(()=>Object.entries(active.value).flatMap(([key,values])=>values.map(value=>({key,value,label:`${labels[key] || key}: ${display(key,value)}`}))));
const navigate=filters=>router.get(queryUrl(page.props.currentUrl,{...reportFilters(page.url),from:props.period.from,to:props.period.to,filters,value:null,source_group:null,provider:null}),{}, {preserveScroll:true});
const remove=(key,value)=>navigate({...active.value,[key]:active.value[key].filter(item=>item!==value)});
const apply=()=>{navigate({...active.value,[dimension.value]:[...new Set(selected.value)]});open.value=false;};
let controller, timer;
async function load(){
 controller?.abort();controller=new AbortController();loading.value=true;error.value='';
 try{const response=await fetch(queryUrl(props.siteLinks['filter-options'],{from:props.period.from,to:props.period.to,filters:active.value,filter_dimension:dimension.value,q:search.value}),{signal:controller.signal,headers:{Accept:'application/json'}});if(!response.ok)throw Error();options.value=(await response.json()).data;}
 catch(e){if(e.name!=='AbortError')error.value='Values could not be loaded. You can enter an exact value below.';}
 finally{loading.value=false;}
}
watch([open,dimension],()=>{if(open.value){selected.value=[...(active.value[dimension.value] || [])];search.value='';load();}});
watch(search,()=>{clearTimeout(timer);timer=setTimeout(load,250);});
onBeforeUnmount(()=>{clearTimeout(timer);controller?.abort();});
</script>
<template>
<section aria-label="Report filters" class="flex flex-wrap items-center gap-2 mb-5">
 <UPopover v-model:open="open" :content="{align:'start'}"><UButton icon="i-tabler-filter-plus" label="Add filter" color="neutral" variant="outline" size="sm" />
 <template #content><div class="p-4 w-80 max-w-[90vw] space-y-3"><p class="font-medium text-sm">Filter measured data</p><USelect v-model="dimension" :items="dimensions" aria-label="Filter dimension" class="w-full"/><UInput v-model="search" placeholder="Find or enter an exact value" aria-label="Find filter values" class="w-full"/><p v-if="error" role="alert" class="text-xs text-warning">{{error}}</p><p v-if="loading" role="status" class="text-xs text-muted">Loading values…</p><div class="max-h-56 overflow-auto space-y-2"><label v-for="value in [...new Set([...selected,...options])]" :key="value" class="flex items-start gap-2 text-sm break-all"><input v-model="selected" type="checkbox" :value="value" class="mt-1"/>{{display(dimension,value)}}</label></div><UButton v-if="search && !selected.includes(search)" :label="'Use exact value: '+search" color="neutral" variant="ghost" size="xs" @click="selected.push(search)"/><p class="text-xs text-muted">Any selected value · all different filters must match.</p><UButton label="Apply filter" :disabled="!selected.length" @click="apply"/></div></template></UPopover>
 <UButton v-for="chip in chips" :key="chip.key+chip.value" :label="chip.label" trailing-icon="i-tabler-x" color="neutral" variant="soft" size="sm" class="max-w-full [&>span]:truncate" :title="chip.label" :aria-label="'Remove '+chip.label" @click="remove(chip.key,chip.value)"/>
 <UButton v-if="chips.length" label="Clear filters" color="neutral" variant="ghost" size="xs" @click="navigate({})"/>
</section>
</template>
