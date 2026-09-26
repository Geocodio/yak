import { StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, Th, Tr } from '@geocodio/console-ui';
import { Empty, Section, TableScroll } from '@/components/analytics/Section';
import { formatCount, formatMs } from '@/lib/format';
import type { StageRow } from '@/types/analytics';

export function StagesTable({ stages }: { stages: StageRow[] }) {
    const hasData = stages.some((row) => row.n > 0);

    return (
        <Section title="Where the time goes" hint="Per-run stage durations, plus CI wait after the push. Percentiles over the runs that recorded the stage.">
            {hasData ? (
                <TableScroll>
                    <StackedTable className="w-full">
                        <StackedThead>
                            <Tr>
                                <Th className="pl-4">Stage</Th>
                                <Th className="md:text-right">p50</Th>
                                <Th className="md:text-right">p90</Th>
                                <Th className="md:text-right">p99</Th>
                                <Th className="pr-4 md:text-right">n</Th>
                            </Tr>
                        </StackedThead>
                        <StackedTbody>
                            {stages.map((row) => (
                                <StackedTr key={row.stage}>
                                    <StackedTd label="Stage" className="pl-4">
                                        {row.label}
                                    </StackedTd>
                                    <StackedTd label="p50" className="tnum md:text-right font-medium md:whitespace-nowrap">
                                        {formatMs(row.p50)}
                                    </StackedTd>
                                    <StackedTd label="p90" className="tnum md:text-right md:whitespace-nowrap text-muted">
                                        {formatMs(row.p90)}
                                    </StackedTd>
                                    <StackedTd label="p99" className="tnum md:text-right md:whitespace-nowrap text-muted">
                                        {formatMs(row.p99)}
                                    </StackedTd>
                                    <StackedTd label="n" className="tnum pr-4 md:text-right text-muted">
                                        {formatCount(row.n)}
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
