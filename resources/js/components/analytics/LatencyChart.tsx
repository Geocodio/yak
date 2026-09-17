import { useMemo } from 'react';
import { EChart, type EChartsOption } from '@/components/analytics/EChart';
import { niceDurationAxis, percentileColors, useChartTheme } from '@/components/analytics/chartTheme';
import { Empty, Section } from '@/components/analytics/Section';
import { asParams, tooltipHtml } from '@/components/analytics/tooltip';
import { formatCount, formatDay, formatMs } from '@/lib/format';
import type { LatencySeries } from '@/types/analytics';

/**
 * A point with no neighbour on either side draws no line segment, so it
 * carries a visible marker; connected points keep their marker for hover.
 */
function pointsFor(values: (number | null)[]): ({ value: number; itemStyle: { opacity: number } } | null)[] {
    return values.map((value, index) => {
        if (value === null) {
            return null;
        }

        const isolated = (values[index - 1] ?? null) === null && (values[index + 1] ?? null) === null;

        return { value, itemStyle: { opacity: isolated ? 1 : 0 } };
    });
}

export function LatencyChart({ latency }: { latency: LatencySeries }) {
    const { tokens, palette } = useChartTheme();
    const hasData = latency.n.some((n) => n > 0);

    const option = useMemo<EChartsOption>(() => {
        const colors = percentileColors(palette);
        const lines: { key: 'p50' | 'p90' | 'p99'; color: string }[] = [
            { key: 'p50', color: colors.p50 },
            { key: 'p90', color: colors.p90 },
            { key: 'p99', color: colors.p99 },
        ];

        const axis = niceDurationAxis(Math.max(0, ...latency.p99.map((v) => v ?? 0)));

        return {
            legend: { show: true },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'line' },
                formatter: (raw: unknown) => {
                    const params = asParams(raw);
                    const index = params[0]?.dataIndex ?? 0;
                    const rows = lines.map((line) => ({ color: line.color, label: line.key, value: formatMs(latency[line.key][index] ?? null) }));

                    return tooltipHtml(formatDay(latency.days[index] ?? ''), [...rows, { label: 'PRs', value: formatCount(latency.n[index] ?? 0) }]);
                },
            },
            xAxis: { type: 'category', boundaryGap: false, data: latency.days.map(formatDay) },
            yAxis: { type: 'value', min: 0, max: axis.max, interval: axis.interval, axisLabel: { formatter: (v: number) => formatMs(v) } },
            series: lines.map((line) => ({
                type: 'line' as const,
                name: line.key,
                data: pointsFor(latency[line.key]),
                connectNulls: false,
                showSymbol: true,
                symbol: 'circle',
                symbolSize: 8,
                lineStyle: { width: 2, color: line.color },
                itemStyle: { color: line.color, borderColor: tokens.panel, borderWidth: 2 },
                emphasis: { scale: 1.25, focus: 'series' as const, itemStyle: { opacity: 1 } },
            })),
        };
    }, [latency, palette, tokens.panel]);

    return (
        <Section title="Time to PR" hint="Request to PR opened, by the day the PR opened. Days without a PR leave a gap." testId="analytics-latency">
            {hasData ? <EChart option={option} height={240} /> : <Empty>No PRs opened in this period.</Empty>}
        </Section>
    );
}
