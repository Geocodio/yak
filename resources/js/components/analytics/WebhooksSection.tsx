import { useMemo } from 'react';
import { StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, Th, Tr } from '@geocodio/console-ui';
import { EChart, type EChartsOption } from '@/components/analytics/EChart';
import { seriesColor, useChartTheme } from '@/components/analytics/chartTheme';
import { Empty, Section } from '@/components/analytics/Section';
import { asParams, numericValue, tooltipHtml } from '@/components/analytics/tooltip';
import { formatCount } from '@/lib/format';
import type { WebhookChannelRow, WebhookSummary } from '@/types/analytics';

type Outcome = Exclude<keyof WebhookChannelRow, 'channel'>;

/** Fixed order: each outcome keeps its slot whether or not a channel saw it. */
const OUTCOMES: Outcome[] = ['accepted', 'skipped', 'rejected', 'duplicate', 'ignored', 'error'];

export function WebhooksSection({ webhooks }: { webhooks: WebhookSummary }) {
    const { tokens, palette } = useChartTheme();
    const channels = webhooks.byChannel;
    const hasData = channels.length > 0;

    const option = useMemo<EChartsOption>(
        () => ({
            legend: { show: true },
            grid: { left: 4, right: 12, top: 32, bottom: 4, containLabel: true },
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
            xAxis: { type: 'value', minInterval: 1, axisLabel: { formatter: (v: number) => formatCount(v) } },
            yAxis: { type: 'category', inverse: true, data: channels.map((row) => row.channel), axisLabel: { color: tokens.text2 } },
            series: OUTCOMES.map((outcome, index) => ({
                type: 'bar' as const,
                name: outcome,
                stack: 'deliveries',
                data: channels.map((row) => row[outcome]),
                barMaxWidth: 20,
                itemStyle: { color: seriesColor(index + 1, palette), borderColor: tokens.panel, borderWidth: 1 },
                emphasis: { focus: 'series' as const },
            })),
        }),
        [channels, palette, tokens.panel, tokens.text2],
    );

    return (
        <Section title="Webhooks" hint="Inbound deliveries per channel and what Yak did with each one.">
            {hasData ? (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <EChart option={option} height={Math.max(160, 36 * channels.length + 48)} />
                    <div className="md:overflow-x-auto">
                        {webhooks.reasons.length > 0 ? (
                            <StackedTable className="w-full">
                                <StackedThead>
                                    <Tr>
                                        <Th>Channel</Th>
                                        <Th>Outcome</Th>
                                        <Th>Reason</Th>
                                        <Th className="md:text-right">Count</Th>
                                    </Tr>
                                </StackedThead>
                                <StackedTbody>
                                    {webhooks.reasons.map((row) => (
                                        <StackedTr key={`${row.channel}:${row.outcome}:${row.reason}`}>
                                            <StackedTd label="Channel" className="text-muted">
                                                {row.channel}
                                            </StackedTd>
                                            <StackedTd label="Outcome" className="text-muted">
                                                {row.outcome}
                                            </StackedTd>
                                            <StackedTd label="Reason" className="font-mono text-[12px]">
                                                {row.reason}
                                            </StackedTd>
                                            <StackedTd label="Count" className="tnum md:text-right font-medium">
                                                {formatCount(row.count)}
                                            </StackedTd>
                                        </StackedTr>
                                    ))}
                                </StackedTbody>
                            </StackedTable>
                        ) : (
                            <p className="py-6 text-center text-[12px] text-muted">Every delivery was accepted; no reasons to show.</p>
                        )}
                    </div>
                </div>
            ) : (
                <Empty>No webhook deliveries in this period.</Empty>
            )}
        </Section>
    );
}
