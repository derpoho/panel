import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { SVGRenderer } from 'echarts/renderers';

/**
 * ECharts with only what TimeSeriesChart draws, in a module of its own: the
 * chart loads it once it's on screen, so pages whose charts sit on another
 * tab don't download it (some 165 KB) up front.
 */
echarts.use([LineChart, GridComponent, TooltipComponent, SVGRenderer]);

export { echarts };
