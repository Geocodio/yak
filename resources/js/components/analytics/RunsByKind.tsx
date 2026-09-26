import { StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, Th, Tr } from '@geocodio/console-ui';
import { Empty, MiniStat, Section, TableScroll } from '@/components/analytics/Section';
import { formatCount, formatMs, formatPct, formatUsd } from '@/lib/format';
import type { RunKindRow, TokenSummary } from '@/types/analytics';

export function RunsByKind({ runsByKind, tokens }: { runsByKind: RunKindRow[]; tokens: TokenSummary }) {
    return (
        <Section title="Runs by kind" hint="Outcomes, cost and effort per agent run, grouped by what kind of run it was.">
            {runsByKind.length > 0 ? (
                <TableScroll className="md:mb-0">
                    <StackedTable className="w-full">
                        <StackedThead>
                            <Tr>
                                <Th className="pl-4">Kind</Th>
                                <Th className="md:text-right">Runs</Th>
                                <Th className="md:text-right">Success</Th>
                                <Th className="md:text-right">Error</Th>
                                <Th className="md:text-right">Cost</Th>
                                <Th className="md:text-right">Avg cost</Th>
                                <Th className="md:text-right">Avg turns</Th>
                                <Th className="pr-4 md:text-right">Avg agent time</Th>
                            </Tr>
                        </StackedThead>
                        <StackedTbody>
                            {runsByKind.map((row) => (
                                <StackedTr key={row.kind}>
                                    <StackedTd label="Kind" className="pl-4">
                                        {row.kind}
                                    </StackedTd>
                                    <StackedTd label="Runs" className="tnum md:text-right font-medium">
                                        {formatCount(row.runs)}
                                    </StackedTd>
                                    <StackedTd label="Success" className="tnum md:text-right text-muted">
                                        {formatCount(row.success)}
                                    </StackedTd>
                                    <StackedTd label="Error" className="tnum md:text-right text-muted">
                                        {formatCount(row.error)}
                                    </StackedTd>
                                    <StackedTd label="Cost" className="tnum md:text-right text-muted">
                                        {formatUsd(row.costUsd)}
                                    </StackedTd>
                                    <StackedTd label="Avg cost" className="tnum md:text-right text-muted">
                                        {formatUsd(row.avgCostUsd)}
                                    </StackedTd>
                                    <StackedTd label="Avg turns" className="tnum md:text-right text-muted">
                                        {formatCount(row.avgTurns)}
                                    </StackedTd>
                                    <StackedTd label="Avg agent time" className="tnum pr-4 md:text-right text-muted">
                                        {formatMs(row.avgAgentMs)}
                                    </StackedTd>
                                </StackedTr>
                            ))}
                        </StackedTbody>
                    </StackedTable>
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
