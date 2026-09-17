import { useMemo } from 'react';
import { EChart, type EChartsOption } from '@/components/analytics/EChart';
import { colorFor, useChartTheme } from '@/components/analytics/chartTheme';
import { Empty, Section } from '@/components/analytics/Section';
import { asParams, numericValue, tooltipHtml } from '@/components/analytics/tooltip';
import { formatCount, formatDay } from '@/lib/format';
import type { DailySeries } from '@/types/analytics';

export function ThroughputChart({ throughput, sourceKeys }: { throughput: DailySeries; sourceKeys: string[] }) {
    const { tokens, palette } = useChartTheme();
    const keys = useMemo(() => [...new Set([...sourceKeys, ...throughput.series.map((s) => s.key)])], [sourceKeys, throughput.series]);
    const hasData = throughput.series.some((s) => s.values.some((v) => v > 0));
    const multi = throughput.series.length > 1;

    const option = useMemo<EChartsOption>(
        () => ({
            legend: { show: multi },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'shadow' },
                formatter: (raw: unknown) => {
                    const params = asParams(raw);
                    const total = params.reduce((sum, p) => sum + (numericValue(p) ?? 0), 0);
                    const rows = params
                        .filter((p) => (numericValue(p) ?? 0) > 0)
                        .map((p) => ({ color: typeof p.color === 'string' ? p.color : undefined, label: String(p.seriesName), value: formatCount(numericValue(p)) }));

                    return tooltipHtml(String(params[0]?.name ?? ''), [...rows, { label: 'Total', value: formatCount(total) }]);
                },
            },
            xAxis: { type: 'category', data: throughput.days.map(formatDay), axisLabel: { interval: 'auto' } },
            yAxis: { type: 'value', minInterval: 1, axisLabel: { formatter: (v: number) => formatCount(v) } },
            series: throughput.series.map((s) => ({
                type: 'bar' as const,
                name: s.key,
                stack: 'tasks',
                data: s.values,
                barMaxWidth: 24,
                itemStyle: { color: colorFor(s.key, keys, palette), borderColor: tokens.panel, borderWidth: 1 },
                emphasis: { focus: 'series' as const },
            })),
        }),
        [throughput, keys, multi, palette, tokens.panel],
    );

    return (
        <Section title="Throughput" hint="Tasks created per day, split by the channel that asked for them.">
            {hasData ? <EChart option={option} height={240} /> : <Empty>No tasks in this period.</Empty>}
        </Section>
    );
}
