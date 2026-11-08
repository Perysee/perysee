<script setup>
import {ref,onMounted,onBeforeUnmount,watch,nextTick} from 'vue';
import {sankeyOption} from '../sankey-options.mjs';
const props=defineProps(['report']);
const panel=ref(), element=ref(), expanded=ref(false), failed=ref(false);
let chart, resize, themeObserver, disposed=false, previousFocus;
const theme=()=>{
 const style=getComputedStyle(document.documentElement), canvas=document.createElement('canvas');canvas.width=canvas.height=1;const ctx=canvas.getContext('2d');
 const value=(key,fallback)=>{const probe=document.createElement('span');probe.style.color=style.getPropertyValue(key).trim() || fallback;element.value.append(probe);const resolved=getComputedStyle(probe).color;probe.remove();if(!ctx)return resolved;ctx.clearRect(0,0,1,1);ctx.fillStyle=resolved;ctx.fillRect(0,0,1,1);const [r,g,b,a]=ctx.getImageData(0,0,1,1).data;return `rgba(${r},${g},${b},${a/255})`;};
 return {primary:value('--ui-primary','#0066CC'),text:value('--ui-text','#1D1D1F'),muted:value('--ui-text-muted','#646466'),background:value('--ui-bg','#FBFBFD'),border:value('--ui-border','#E5E5E7'),warning:value('--ui-warning','#D19524'),reduced:matchMedia('(prefers-reduced-motion: reduce)').matches};
};
const draw=()=>{if(chart && props.report)chart.setOption(sankeyOption(props.report,theme()),{notMerge:true});};
const key=e=>{if(!expanded.value)return;if(e.key==='Escape')expanded.value=false;if(e.key==='Tab'){const items=[...panel.value.querySelectorAll('button,a[href],[tabindex="0"]')];const first=items[0],last=items.at(-1);if(e.shiftKey && (document.activeElement===first || document.activeElement===panel.value)){e.preventDefault();last?.focus();}else if(!e.shiftKey && (document.activeElement===last || document.activeElement===panel.value)){e.preventDefault();first?.focus();}}};
onMounted(async()=>{
 try{const module=await import('../sankey-charts.js');if(disposed)return;chart=module.create(element.value);draw();resize=new ResizeObserver(()=>chart?.resize());resize.observe(element.value);themeObserver=new MutationObserver(draw);themeObserver.observe(document.documentElement,{attributes:true,attributeFilter:['class']});document.addEventListener('keydown',key);}
 catch{failed.value=true;}
});
watch(()=>props.report,draw,{deep:true});
watch(expanded,async value=>{if(value)previousFocus=document.activeElement;await nextTick();if(value)panel.value?.focus();else previousFocus?.focus();chart?.resize();});
onBeforeUnmount(()=>{disposed=true;resize?.disconnect();themeObserver?.disconnect();chart?.dispose();document.removeEventListener('keydown',key);});
</script>
<template>
<section ref="panel" tabindex="-1" :aria-modal="expanded ? true : undefined" :class="expanded ? 'fixed inset-4 z-50 bg-default border border-default p-5 overflow-auto shadow-xl' : 'report-panel p-5'" :role="expanded ? 'dialog' : 'region'" aria-label="Session flow Sankey">
 <header class="flex items-center justify-between gap-4 mb-4"><div><h2 class="text-sm font-medium">Session flow</h2><p class="text-xs text-muted mt-1">Proportional flows · select a link for its measured volume</p></div><UButton :icon="expanded?'i-tabler-arrows-minimize':'i-tabler-arrows-maximize'" :aria-label="expanded?'Close expanded chart':'Expand chart'" color="neutral" variant="ghost" @click="expanded=!expanded"/></header>
 <p v-if="failed" role="alert" class="text-sm text-warning">Chart unavailable. Exact values remain in the table below.</p>
 <div class="overflow-x-auto"><div ref="element" role="img" aria-label="Sankey of session progression, exits and pending sessions. See the following table for exact values." :style="{minWidth:'900px',height:Math.max(430,Math.min(1100,report.nodes.length*24))+'px'}" /></div>
 <div class="flex flex-wrap gap-5 text-xs text-muted mt-4"><span>● Progression</span><span>◌ Abandoned / exited</span><span>◷ In progress</span><span>Other / depth limit are grouped terminal flows</span></div>
</section>
</template>
