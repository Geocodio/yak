import * as echarts from 'echarts/core';
import { BarChart, LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { SVGRenderer } from 'echarts/renderers';
import type { ComposeOption, ECharts } from 'echarts/core';
import type { BarSeriesOption, LineSeriesOption } from 'echarts/charts';
import type { GridComponentOption, LegendComponentOption, TooltipComponentOption } from 'echarts/components';
import { useEffect, useRef } from 'react';
import { cn } from '@geocodio/console-ui';
import { readChartTokens, type ChartTokens } from '@/components/analytics/chartTheme';

echarts.use([BarChart, LineChart, GridComponent, TooltipComponent, LegendComponent, SVGRenderer]);

export type EChartsOption = ComposeOption<
    BarSeriesOption | LineSeriesOption | GridComponentOption | TooltipComponentOption | LegendComponentOption
>;

type AxisOption = NonNullable<EChartsOption['xAxis']> | NonNullable<EChartsOption['yAxis']>;
type SingleAxis = Exclude<AxisOption, readonly unknown[]>;
type SingleTooltip = Exclude<NonNullable<EChartsOption['tooltip']>, readonly unknown[]>;
type SingleLegend = Exclude<NonNullable<EChartsOption['legend']>, readonly unknown[]>;

/**
 * The chrome every chart shares -- hairline solid grid, quiet axis labels,
 * panel-coloured tooltip -- merged under the caller's option so a section
 * only describes its data. Tokens are read fresh each time so a scheme flip
 * repaints axes and tooltips without the caller re-rendering.
 */
function styleAxis(axis: SingleAxis, tokens: ChartTokens): SingleAxis {
    return {
        ...axis,
        axisLine: { show: false, lineStyle: { color: tokens.border }, ...axis.axisLine },
        axisTick: { show: false, ...axis.axisTick },
        axisLabel: { color: tokens.text3, fontSize: 11, margin: 8, hideOverlap: true, ...axis.axisLabel },
        splitLine: { show: axis.type !== 'category', lineStyle: { color: tokens.border, type: 'solid', width: 1 }, ...axis.splitLine },
    } as SingleAxis;
}

function mapAxis(axis: AxisOption | undefined, tokens: ChartTokens): AxisOption | undefined {
    if (axis === undefined) {
        return undefined;
    }

    if (Array.isArray(axis)) {
        return axis.map((one) => styleAxis(one as SingleAxis, tokens)) as AxisOption;
    }

    return styleAxis(axis as SingleAxis, tokens);
}

export function applyChrome(option: EChartsOption, tokens: ChartTokens): EChartsOption {
    const tooltip = (Array.isArray(option.tooltip) ? option.tooltip[0] : option.tooltip) as SingleTooltip | undefined;
    const legend = (Array.isArray(option.legend) ? option.legend[0] : option.legend) as SingleLegend | undefined;
    const hasLegend = legend !== undefined && legend.show !== false;

    return {
        animationDuration: 300,
        textStyle: { fontFamily: tokens.fontFamily, color: tokens.text2 },
        ...option,
        grid: {
            left: 8,
            right: 12,
            top: hasLegend ? 32 : 12,
            bottom: 4,
            containLabel: true,
            ...option.grid,
        },
        tooltip: {
            show: true,
            trigger: 'item',
            confine: true,
            backgroundColor: tokens.panel,
            borderColor: tokens.border,
            borderWidth: 1,
            padding: [6, 10],
            textStyle: { color: tokens.text, fontSize: 12, fontFamily: tokens.fontFamily },
            extraCssText: 'box-shadow: 0 6px 24px rgba(0,0,0,0.12); border-radius: 6px;',
            ...tooltip,
            axisPointer: {
                lineStyle: { color: tokens.borderStrong, width: 1 },
                shadowStyle: { color: tokens.panel2, opacity: 0.5 },
                ...tooltip?.axisPointer,
            },
        },
        legend: hasLegend
            ? {
                  top: 0,
                  left: 0,
                  icon: 'roundRect',
                  itemWidth: 10,
                  itemHeight: 10,
                  itemGap: 14,
                  inactiveColor: tokens.text3,
                  textStyle: { color: tokens.text2, fontSize: 11 },
                  ...legend,
              }
            : { show: false },
        xAxis: mapAxis(option.xAxis, tokens) as EChartsOption['xAxis'],
        yAxis: mapAxis(option.yAxis, tokens) as EChartsOption['yAxis'],
    };
}

export function EChart({
    option,
    height = 240,
    className,
    onReady,
}: {
    option: EChartsOption;
    height?: number;
    className?: string;
    onReady?: (chart: ECharts) => void;
}) {
    const hostRef = useRef<HTMLDivElement>(null);
    const chartRef = useRef<ECharts | null>(null);
    const optionRef = useRef(option);
    optionRef.current = option;

    useEffect(() => {
        const host = hostRef.current;

        if (!host) {
            return;
        }

        const chart = echarts.init(host, undefined, { renderer: 'svg' });
        chartRef.current = chart;
        chart.setOption(applyChrome(optionRef.current, readChartTokens()), { notMerge: true });
        onReady?.(chart);

        // Resize on the next frame: resizing synchronously inside the observer
        // callback changes layout again and trips the browser's
        // "ResizeObserver loop completed with undelivered notifications" error.
        let frame = 0;
        const observer = new ResizeObserver(() => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => chart.resize());
        });
        observer.observe(host);

        const repaint = () => chart.setOption(applyChrome(optionRef.current, readChartTokens()), { notMerge: true });
        const scheme = typeof window.matchMedia === 'function' ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        scheme?.addEventListener('change', repaint);

        return () => {
            scheme?.removeEventListener('change', repaint);
            cancelAnimationFrame(frame);
            observer.disconnect();
            chart.dispose();
            chartRef.current = null;
        };
        // The chart is created once; option changes are applied by the effect below.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        chartRef.current?.setOption(applyChrome(option, readChartTokens()), { notMerge: true });
    }, [option]);

    return <div ref={hostRef} className={cn('w-full', className)} style={{ height }} />;
}
