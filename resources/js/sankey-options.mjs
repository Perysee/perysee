export function sankeyOption(report, theme = {}) {
    const colors = {step:theme.primary || '#0066CC',abandoned:theme.muted || '#646466',exit:theme.muted || '#646466',pending:theme.warning || '#D19524',other:theme.muted || '#646466',limit:theme.muted || '#646466'};
    const labels = new Map(report.nodes.map(node=>[node.name,node.label]));
    return {
        animation:!theme.reduced,animationDuration:350,
        textStyle:{fontFamily:'Aspekta, system-ui, sans-serif',color:theme.text || '#1D1D1F'},
        aria:{enabled:true,description:'Session flows through measured steps. Link widths show session counts. Exact counts are available in the table below.'},
        tooltip:{trigger:'item',renderMode:'richText',confine:true,backgroundColor:theme.background || '#FBFBFD',borderColor:theme.border,textStyle:{color:theme.text},formatter:params=>{
            const data=params.data;
            const label=data.source ? `${labels.get(data.source)} → ${labels.get(data.target)}` : labels.get(data.name);
            return `${label}\n${new Intl.NumberFormat('en').format(data.value || 0)} sessions${report.entries ? ` · ${((data.value || 0)*100/report.entries).toFixed(1)}% of entries` : ''}`;
        }},
        series:[{type:'sankey',orient:'horizontal',left:20,right:200,top:35,bottom:35,nodeWidth:12,nodeGap:24,nodeAlign:'left',draggable:false,
            emphasis:{focus:'adjacency'},label:{color:theme.text,fontSize:12,width:180,overflow:'truncate',formatter:p=>labels.get(p.name) || p.name},
            lineStyle:{color:'gradient',opacity:.35,curveness:.5},
            data:report.nodes.map(node=>({...node,itemStyle:{color:colors[node.kind] || colors.step,borderWidth:0}})),
            links:report.links.map(link=>({...link})),
        }],
    };
}
