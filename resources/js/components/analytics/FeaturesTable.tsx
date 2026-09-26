import { StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, Th, Tr } from '@geocodio/console-ui';
import { Empty, Section, TableScroll } from '@/components/analytics/Section';
import { formatCount } from '@/lib/format';
import type { FeatureRow } from '@/types/analytics';

function bySource(sources: Record<string, number>): string {
    return Object.entries(sources)
        .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
        .map(([source, count]) => `${source} ${formatCount(count)}`)
        .join(' · ');
}

export function FeaturesTable({ features }: { features: FeatureRow[] }) {
    return (
        <Section title="Feature usage" hint="How often each feature was used, and which channel it came from.">
            {features.length > 0 ? (
                <TableScroll>
                    <StackedTable className="w-full">
                        <StackedThead>
                            <Tr>
                                <Th className="pl-4">Feature</Th>
                                <Th className="md:text-right">Uses</Th>
                                <Th className="pr-4">By source</Th>
                            </Tr>
                        </StackedThead>
                        <StackedTbody>
                            {features.map((row) => (
                                <StackedTr key={row.feature}>
                                    <StackedTd label="Feature" className="pl-4 font-mono text-[12px]">
                                        {row.feature}
                                    </StackedTd>
                                    <StackedTd label="Uses" className="tnum md:text-right font-medium">
                                        {formatCount(row.count)}
                                    </StackedTd>
                                    <StackedTd label="By source" className="tnum pr-4 text-muted">
                                        {bySource(row.sources) || '–'}
                                    </StackedTd>
                                </StackedTr>
                            ))}
                        </StackedTbody>
                    </StackedTable>
                </TableScroll>
            ) : (
                <Empty>No feature usage recorded in this period.</Empty>
            )}
        </Section>
    );
}
