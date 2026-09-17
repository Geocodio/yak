import { Table, Tbody, Td, Th, Thead, Tr } from '@geocodio/console-ui';
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
                    <div className="overflow-x-auto">
                        <Table className="w-full">
                            <Thead>
                                <Tr>
                                    <Th>Tool</Th>
                                    <Th className="text-right">Calls</Th>
                                    <Th className="text-right">Errors</Th>
                                    <Th className="text-right">Avg</Th>
                                </Tr>
                            </Thead>
                            <Tbody>
                                {tools.map((row) => (
                                    <Tr key={row.tool}>
                                        <Td className="font-mono text-[12px]">{row.tool}</Td>
                                        <Td className="tnum text-right font-medium">{formatCount(row.calls)}</Td>
                                        <Td className="tnum text-right text-muted">{formatCount(row.errors)}</Td>
                                        <Td className="tnum text-right text-muted">{formatMs(row.avgMs)}</Td>
                                    </Tr>
                                ))}
                            </Tbody>
                        </Table>
                    </div>
                </div>
            ) : (
                <Empty>No tool calls recorded in this period.</Empty>
            )}
        </Section>
    );
}
