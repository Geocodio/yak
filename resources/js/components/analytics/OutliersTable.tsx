import { Link } from '@inertiajs/react';
import { Badge, Table, Tbody, Td, Th, Thead, Tr, type BadgeTone } from '@geocodio/console-ui';
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
                    <Table className="w-full">
                        <Thead>
                            <Tr>
                                <Th className="pl-4">Task</Th>
                                <Th>Repo</Th>
                                <Th>Kind</Th>
                                <Th>Outcome</Th>
                                <Th className="text-right">Total</Th>
                                <Th className="text-right">Agent</Th>
                                <Th className="text-right">Tools</Th>
                                <Th className="text-right">Turns</Th>
                                <Th className="text-right">Cost</Th>
                                <Th className="pr-4 text-right">Started</Th>
                            </Tr>
                        </Thead>
                        <Tbody>
                            {outliers.map((row) => (
                                <Tr key={row.runId}>
                                    <Td className="pl-4 whitespace-nowrap">
                                        <Link href={row.taskUrl} className="text-[12px] text-accent">
                                            {row.externalId ?? `#${row.taskId}`}
                                        </Link>
                                    </Td>
                                    <Td className="font-mono text-[12px] text-muted">{row.repo ?? '–'}</Td>
                                    <Td className="text-muted">{row.kind}</Td>
                                    <Td>{row.outcome ? <Badge tone={outcomeTone(row.outcome)}>{row.outcome}</Badge> : <span className="text-faint">–</span>}</Td>
                                    <Td className="tnum text-right font-medium whitespace-nowrap">{formatMs(row.totalMs)}</Td>
                                    <Td className="tnum text-right whitespace-nowrap text-muted">{formatMs(row.agentMs)}</Td>
                                    <Td className="tnum text-right whitespace-nowrap text-muted">{formatMs(row.toolMs)}</Td>
                                    <Td className="tnum text-right text-muted">{formatCount(row.numTurns)}</Td>
                                    <Td className="tnum text-right text-muted">{formatUsd(row.costUsd)}</Td>
                                    <Td className="tnum pr-4 text-right whitespace-nowrap text-muted">{new Date(row.startedAt).toLocaleString()}</Td>
                                </Tr>
                            ))}
                        </Tbody>
                    </Table>
                </TableScroll>
            ) : (
                <Empty>No runs in this period.</Empty>
            )}
        </Section>
    );
}
