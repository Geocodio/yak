import { Table, Tbody, Td, Th, Thead, Tr } from '@geocodio/console-ui';
import { Empty, Section, TableScroll } from '@/components/analytics/Section';
import { formatCount, formatMs } from '@/lib/format';
import type { StageRow } from '@/types/analytics';

export function StagesTable({ stages }: { stages: StageRow[] }) {
    const hasData = stages.some((row) => row.n > 0);

    return (
        <Section title="Where the time goes" hint="Per-run stage durations, plus CI wait after the push. Percentiles over the runs that recorded the stage.">
            {hasData ? (
                <TableScroll>
                    <Table className="w-full">
                        <Thead>
                            <Tr>
                                <Th className="pl-4">Stage</Th>
                                <Th className="text-right">p50</Th>
                                <Th className="text-right">p90</Th>
                                <Th className="text-right">p99</Th>
                                <Th className="pr-4 text-right">n</Th>
                            </Tr>
                        </Thead>
                        <Tbody>
                            {stages.map((row) => (
                                <Tr key={row.stage}>
                                    <Td className="pl-4">{row.label}</Td>
                                    <Td className="tnum text-right font-medium whitespace-nowrap">{formatMs(row.p50)}</Td>
                                    <Td className="tnum text-right whitespace-nowrap text-muted">{formatMs(row.p90)}</Td>
                                    <Td className="tnum text-right whitespace-nowrap text-muted">{formatMs(row.p99)}</Td>
                                    <Td className="tnum pr-4 text-right text-muted">{formatCount(row.n)}</Td>
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
