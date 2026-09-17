import { useMemo } from 'react';
import { EChart, type EChartsOption } from '@/components/analytics/EChart';
import { singleHue, useChartTheme } from '@/components/analytics/chartTheme';
import { Empty, Section } from '@/components/analytics/Section';
import { asParams, numericValue, tooltipHtml } from '@/components/analytics/tooltip';
import { formatCount } from '@/lib/format';
import type { FunnelStage } from '@/types/analytics';

function stageLabel(stages: FunnelStage[], index: number): string {
    const stage = stages[index];
    const previous = index > 0 ? stages[index - 1] : null;
    const pct = previous && previous.count > 0 ? ` (${Math.round((stage.count / previous.count) * 100)}%)` : '';

    return `${stage.label} · ${formatCount(stage.count)}${pct}`;
}

export function FunnelChart({ funnel }: { funnel: FunnelStage[] }) {
    const { tokens, palette } = useChartTheme();
    const hasData = funnel.some((stage) => stage.count > 0);
    const max = Math.max(1, ...funnel.map((stage) => stage.count));

    const option = useMemo<EChartsOption>(
        () => ({
            grid: { left: 4, right: 4, top: 4, bottom: 4, containLabel: false },
            xAxis: { type: 'value', show: false, max: max * 1.55 },
            yAxis: {
                type: 'category',
                inverse: true,
                show: false,
                data: funnel.map((stage) => stage.label),
            },
            tooltip: {
                trigger: 'item',
                formatter: (raw: unknown) => {
                    const [param] = asParams(raw);

                    return param ? tooltipHtml(String(param.name), [{ color: singleHue(palette), label: 'Tasks', value: formatCount(numericValue(param)) }]) : '';
                },
            },
            series: [
                {
                    type: 'bar',
                    data: funnel.map((stage) => stage.count),
                    barMaxWidth: 22,
                    barCategoryGap: '35%',
                    itemStyle: { color: singleHue(palette), borderRadius: [0, 4, 4, 0] },
                    label: {
                        show: true,
                        position: 'right',
                        distance: 8,
                        color: tokens.text,
                        fontSize: 12,
                        formatter: (param: unknown) => stageLabel(funnel, asParams(param)[0]?.dataIndex ?? 0),
                    },
                },
            ],
        }),
        [funnel, max, palette, tokens.text],
    );

    return (
        <Section title="Funnel" hint="Root fix tasks moving from request to merged PR. The percentage is conversion from the previous stage.">
            {hasData ? <EChart option={option} height={44 * funnel.length + 8} /> : <Empty>No tasks in this period.</Empty>}
        </Section>
    );
}
