import { init, use, registerMap } from 'echarts/core';
import { LineChart, MapChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, VisualMapComponent, AriaComponent } from 'echarts/components';
import { SVGRenderer } from 'echarts/renderers';
import { lineOption, metricLabels } from './chart-options.mjs';
use([LineChart,MapChart,GridComponent,TooltipComponent,VisualMapComponent,AriaComponent,SVGRenderer]);
let worldMap;
export async function renderChart(element) {
    const style = getComputedStyle(document.documentElement);
    const theme = {background:style.getPropertyValue('--ui-bg').trim()||'#F8F8F8',muted:style.getPropertyValue('--ui-bg-accented').trim()||'#F8F8F8',subtle:style.getPropertyValue('--brand-subtle').trim()||'#F8F8F8',primary:style.getPropertyValue('--ui-primary').trim()||'#5516FF',text:style.getPropertyValue('--ui-text-muted').trim()||'#00020C',border:style.getPropertyValue('--ui-border').trim()||'#00020C24',font:getComputedStyle(element).fontFamily,reduced:matchMedia('(prefers-reduced-motion: reduce)').matches};
    const chart = init(element, null, {renderer:'svg'});
    let cleanup;
    try {
        if (element.hasAttribute('data-country-map')) {
            worldMap ??= fetch(element.dataset.mapUrl,{credentials:'same-origin'}).then(response=>{if(!response.ok)throw new Error('Map unavailable');return response.json();});
            const geo = await worldMap;
            registerMap('perysee-world',geo);
            const rows = JSON.parse(element.dataset.countries).filter(row=>/^[A-Z]{2}$/.test(row.value));
            const countryNames = new Map(geo.features.map(feature=>[feature.properties.name,feature.properties.label]));
            const maximum = Math.max(1,...rows.map(row=>row.count));
            chart.setOption({animation:!theme.reduced,textStyle:{fontFamily:theme.font},aria:{enabled:true,description:'World map of measured country pageviews. Unmeasured countries have no data. Exact values are available in the country table below.'},tooltip:{backgroundColor:theme.background,borderColor:theme.border,textStyle:{color:theme.text},trigger:'item',renderMode:'richText',confine:true,formatter:params=>`${countryNames.get(params.name)||params.name}\n${Number.isFinite(params.value)?params.value+' pageviews':'No measured data'}`},visualMap:{min:0,max:maximum,left:20,bottom:5,inRange:{color:[theme.subtle,theme.primary]},text:['More','Less'],calculable:false,textStyle:{color:theme.text}},series:[{type:'map',map:'perysee-world',roam:false,selectedMode:'single',itemStyle:{areaColor:theme.muted,borderColor:theme.background,borderWidth:.6},emphasis:{label:{show:false},itemStyle:{areaColor:theme.primary}},select:{label:{show:false},itemStyle:{areaColor:theme.primary}},data:rows.map(row=>({name:row.value,value:row.count,selected:row.value===new URL(location.href).searchParams.get('value')}))}]});
            chart.on('click',params=>{if(/^[A-Z]{2}$/.test(params.name)){const url=new URL(element.dataset.reportUrl,location.origin);url.searchParams.set('value',params.name);location.href=url.href;}});
        } else {
            const series=JSON.parse(element.dataset.series);
            const compact=element.dataset.compact==='true';
            let metric=element.dataset.metric||'pageviews';
            const panel=element.closest('[data-report-chart]');
            const draw=()=>{chart.setOption(lineOption(series,metric,{...theme,compact}),{notMerge:true});const empty=element.parentElement.querySelector('[data-chart-empty]');if(empty){const values=series.map(point=>point[metric]);empty.hidden=values.some(value=>value>0);empty.textContent=metric==='sessions'&&values.every(value=>value===null)?'Session measurement unavailable':'No measured activity in this selection';}};
            draw();
            const buttons=panel?.querySelectorAll('[data-chart-metric]')||[];
            buttons.forEach(button=>button.addEventListener('click',()=>{metric=button.dataset.chartMetric;buttons.forEach(other=>other.setAttribute('aria-pressed',String(other===button)));panel.querySelector('[data-chart-legend]').textContent=metricLabels[metric];draw();}));
        }
        element.querySelector('.chart-fallback')?.remove();
        const observer=new ResizeObserver(()=>chart.resize());observer.observe(element);
        cleanup=()=>{observer.disconnect();chart.dispose();};
        window.addEventListener('pagehide',event=>{if(!event.persisted)cleanup();},{once:true});
    } catch(error) {
        chart.dispose();
        element.dataset.chartError='true';
        throw error;
    }
}
