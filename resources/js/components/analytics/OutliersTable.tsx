import { Link } from '@inertiajs/react';
import { Badge, StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, Th, Tr, type BadgeTone } from '@geocodio/console-ui';
import { Empty, Section, TableScroll } from '@/components/analytics/Section';
import { formatCount, formatMs, formatUsd } from '@/lib/format';
import type { OutlierRow } from '@/types/analytics';

export function outcomeTone(outcome: string | null): BadgeTone {
    switch (outcome) {
        case 'success':
        case 'no_changes':
            return 'ok';
        case 'clarification':
            return 'warn';
        case 'error':
        case 'exception':
            return 'fail';
        default:
            return 'neutral';
    }
}

export function OutliersTable({ outliers }: { outliers: OutlierRow[] }) {
    return (
        <Section title="Slowest runs" hint="The longest agent runs in the period, wall clock from pickup to teardown." testId="analytics-outliers">
            {outliers.length > 0 ? (
                <TableScroll>
                    <StackedTable className="w-full">
                        <StackedThead>
                            <Tr>
                                <Th className="pl-4">Task</Th>
                                <Th>Repo</Th>
                                <Th>Kind</Th>
                                <Th>Outcome</Th>
                                <Th className="md:text-right">Total</Th>
                                <Th className="md:text-right">Agent</Th>
                                <Th className="md:text-right">Tools</Th>
                                <Th className="md:text-right">Turns</Th>
                                <Th className="md:text-right">Cost</Th>
                                <Th className="pr-4 md:text-right">Started</Th>
                            </Tr>
                        </StackedThead>
                        <StackedTbody>
                            {outliers.map((row) => (
                                <StackedTr key={row.runId}>
                                    <StackedTd label="Task" className="pl-4 md:whitespace-nowrap">
                                        <Link href={row.taskUrl} className="text-[12px] text-accent">
                                            {row.externalId ?? `#${row.taskId}`}
                                        </Link>
                                    </StackedTd>
                                    <StackedTd label="Repo" className="font-mono text-[12px] text-muted">
                                        {row.repo ?? '–'}
                                    </StackedTd>
                                    <StackedTd label="Kind" className="text-muted">
                                        {row.kind}
                                    </StackedTd>
                                    <StackedTd label="Outcome">
                                        {row.outcome ? <Badge tone={outcomeTone(row.outcome)}>{row.outcome}</Badge> : <span className="text-faint">–</span>}
                                    </StackedTd>
                                    <StackedTd label="Total" className="tnum md:text-right font-medium md:whitespace-nowrap">
                                        {formatMs(row.totalMs)}
                                    </StackedTd>
                                    <StackedTd label="Agent" className="tnum md:text-right md:whitespace-nowrap text-muted">
                                        {formatMs(row.agentMs)}
                                    </StackedTd>
                                    <StackedTd label="Tools" className="tnum md:text-right md:whitespace-nowrap text-muted">
                                        {formatMs(row.toolMs)}
                                    </StackedTd>
                                    <StackedTd label="Turns" className="tnum md:text-right text-muted">
                                        {formatCount(row.numTurns)}
                                    </StackedTd>
                                    <StackedTd label="Cost" className="tnum md:text-right text-muted">
                                        {formatUsd(row.costUsd)}
                                    </StackedTd>
                                    <StackedTd label="Started" className="tnum pr-4 md:text-right md:whitespace-nowrap text-muted">
                                        {new Date(row.startedAt).toLocaleString()}
                                    </StackedTd>
                                </StackedTr>
                            ))}
                        </StackedTbody>
                    </StackedTable>
                </TableScroll>
            ) : (
                <Empty>No runs in this period.</Empty>
            )}
        </Section>
    );
}
