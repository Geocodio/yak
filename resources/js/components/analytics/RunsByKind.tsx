import { Table, Tbody, Td, Th, Thead, Tr } from '@geocodio/console-ui';
import { Empty, MiniStat, Section, TableScroll } from '@/components/analytics/Section';
import { formatCount, formatMs, formatPct, formatUsd } from '@/lib/format';
import type { RunKindRow, TokenSummary } from '@/types/analytics';

export function RunsByKind({ runsByKind, tokens }: { runsByKind: RunKindRow[]; tokens: TokenSummary }) {
    return (
        <Section title="Runs by kind" hint="Outcomes, cost and effort per agent run, grouped by what kind of run it was.">
            {runsByKind.length > 0 ? (
                <TableScroll className="mb-0">
                    <Table className="w-full">
                        <Thead>
                            <Tr>
                                <Th className="pl-4">Kind</Th>
                                <Th className="text-right">Runs</Th>
                                <Th className="text-right">Success</Th>
                                <Th className="text-right">Error</Th>
                                <Th className="text-right">Cost</Th>
                                <Th className="text-right">Avg cost</Th>
                                <Th className="text-right">Avg turns</Th>
                                <Th className="pr-4 text-right">Avg agent time</Th>
                            </Tr>
                        </Thead>
                        <Tbody>
                            {runsByKind.map((row) => (
                                <Tr key={row.kind}>
                                    <Td className="pl-4">{row.kind}</Td>
                                    <Td className="tnum text-right font-medium">{formatCount(row.runs)}</Td>
                                    <Td className="tnum text-right text-muted">{formatCount(row.success)}</Td>
                                    <Td className="tnum text-right text-muted">{formatCount(row.error)}</Td>
                                    <Td className="tnum text-right text-muted">{formatUsd(row.costUsd)}</Td>
                                    <Td className="tnum text-right text-muted">{formatUsd(row.avgCostUsd)}</Td>
                                    <Td className="tnum text-right text-muted">{formatCount(row.avgTurns)}</Td>
                                    <Td className="tnum pr-4 text-right text-muted">{formatMs(row.avgAgentMs)}</Td>
                                </Tr>
                            ))}
                        </Tbody>
                    </Table>
                </TableScroll>
            ) : (
                <Empty>No runs in this period.</Empty>
            )}

            <h3 className="mt-5 mb-2 text-[12px] font-medium text-muted">Tokens</h3>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <MiniStat label="Input" value={formatCount(tokens.input)} />
                <MiniStat label="Output" value={formatCount(tokens.output)} />
                <MiniStat label="Cache read" value={formatCount(tokens.cacheRead)} />
                <MiniStat label="Cache creation" value={formatCount(tokens.cacheCreation)} />
                <MiniStat label="Cache hit rate" value={formatPct(tokens.cacheHitRate)} />
                <MiniStat label="API retries" value={formatCount(tokens.apiRetries)} />
                <MiniStat label="Synthesized results" value={formatCount(tokens.synthesizedResults)} />
                <MiniStat label="Permission denials" value={formatCount(tokens.permissionDenials)} />
            </div>
        </Section>
    );
}
