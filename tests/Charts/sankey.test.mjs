import {test} from 'node:test';
import assert from 'node:assert/strict';
import {sankeyOption} from '../../resources/js/sankey-options.mjs';
test('Sankey preserves measured progression and abandonment counts and uses safe text tooltips',()=>{
 const report={entries:10,nodes:[{name:'a',label:'<script>start</script>',kind:'step',value:10},{name:'b',label:'Signup',kind:'step',value:6},{name:'drop',label:'Abandoned',kind:'abandoned',value:4}],links:[{source:'a',target:'b',value:6},{source:'a',target:'drop',value:4}]};
 const options=sankeyOption(report,{reduced:true,primary:'#5516FF',muted:'#777777'});
 assert.deepEqual(options.series[0].links,report.links);
 assert.equal(options.series[0].data[2].itemStyle.color,'#777777');
 assert.equal(options.tooltip.renderMode,'richText');
 assert.equal(options.animation,false);
 assert.match(options.tooltip.formatter({data:report.links[0]}),/6 sessions · 60.0%/);
});
