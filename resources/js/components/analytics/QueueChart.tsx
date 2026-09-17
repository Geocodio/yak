import { useMemo } from 'react';
import { EChart, type EChartsOption } from '@/components/analytics/EChart';
import { colorFor, useChartTheme } from '@/components/analytics/chartTheme';
import { Empty, Section } from '@/components/analytics/Section';
import { asParams, numericValue, tooltipHtml } from '@/components/analytics/tooltip';
import { formatCount, formatHour } from '@/lib/format';
import type { QueueSeries } from '@/types/analytics';

export function QueueChart({ queues }: { queues: QueueSeries }) {
    const { tokens, palette } = useChartTheme();
    const keys = useMemo(() => queues.series.map((s) => s.key), [queues.series]);
    const multi = queues.series.length > 1;

    const option = useMemo<EChartsOption>(
        () => ({
            legend: { show: multi },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'line' },
                formatter: (raw: unknown) => {
                    const params = asParams(raw);
                    const rows = params.map((p) => ({ color: typeof p.color === 'string' ? p.color : undefined, label: String(p.seriesName), value: formatCount(numericValue(p)) }));

                    return tooltipHtml(String(params[0]?.name ?? ''), rows);
                },
            },
            xAxis: { type: 'category', boundaryGap: false, data: queues.hours.map(formatHour) },
            yAxis: { type: 'value', min: 0, minInterval: 1, axisLabel: { formatter: (v: number) => formatCount(v) } },
            series: queues.series.map((s) => {
                const color = colorFor(s.key, keys, palette);

                return {
                    type: 'line' as const,
                    name: s.key,
                    data: s.values,
                    step: 'middle' as const,
                    showSymbol: false,
                    symbol: 'circle',
                    symbolSize: 8,
                    lineStyle: { width: 2, color },
                    itemStyle: { color, borderColor: tokens.panel, borderWidth: 2 },
                    emphasis: { focus: 'series' as const },
                };
            }),
        }),
        [queues, keys, multi, palette, tokens.panel],
    );

    return (
        <Section title="Queue depth" hint="The deepest each worker queue got in every hour it was sampled.">
            {queues.hours.length > 0 ? <EChart option={option} height={220} /> : <Empty>No queue samples in this period.</Empty>}
        </Section>
    );
}
