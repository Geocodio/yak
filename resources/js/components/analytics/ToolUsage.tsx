import { StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, Th, Tr } from '@geocodio/console-ui';
import { HorizontalBars } from '@/components/analytics/HorizontalBars';
import { Empty, Section } from '@/components/analytics/Section';
import { formatCount, formatMs } from '@/lib/format';
import type { ToolRow } from '@/types/analytics';

export function ToolUsage({ tools }: { tools: ToolRow[] }) {
    return (
        <Section title="Tool usage" hint="Tool calls summed across every run's breakdown. Average is wall time per call.">
            {tools.length > 0 ? (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <HorizontalBars rows={tools.map((row) => ({ label: row.tool, value: row.calls }))} valueLabel="Calls" />
                    <div className="md:overflow-x-auto">
                        <StackedTable className="w-full">
                            <StackedThead>
                                <Tr>
                                    <Th>Tool</Th>
                                    <Th className="md:text-right">Calls</Th>
                                    <Th className="md:text-right">Errors</Th>
                                    <Th className="md:text-right">Avg</Th>
                                </Tr>
                            </StackedThead>
                            <StackedTbody>
                                {tools.map((row) => (
                                    <StackedTr key={row.tool}>
                                        <StackedTd label="Tool" className="font-mono text-[12px]">
                                            {row.tool}
                                        </StackedTd>
                                        <StackedTd label="Calls" className="tnum md:text-right font-medium">
                                            {formatCount(row.calls)}
                                        </StackedTd>
                                        <StackedTd label="Errors" className="tnum md:text-right text-muted">
                                            {formatCount(row.errors)}
                                        </StackedTd>
                                        <StackedTd label="Avg" className="tnum md:text-right text-muted">
                                            {formatMs(row.avgMs)}
                                        </StackedTd>
                                    </StackedTr>
                                ))}
                            </StackedTbody>
                        </StackedTable>
                    </div>
                </div>
            ) : (
                <Empty>No tool calls recorded in this period.</Empty>
            )}
        </Section>
    );
}
