import {init,use} from 'echarts/core';
import {SankeyChart} from 'echarts/charts';
import {TooltipComponent,AriaComponent} from 'echarts/components';
import {SVGRenderer} from 'echarts/renderers';
use([SankeyChart,TooltipComponent,AriaComponent,SVGRenderer]);
export const create=element=>init(element,null,{renderer:'svg'});
