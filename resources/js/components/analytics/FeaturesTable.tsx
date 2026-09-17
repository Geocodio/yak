import { Table, Tbody, Td, Th, Thead, Tr } from '@geocodio/console-ui';
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
                    <Table className="w-full">
                        <Thead>
                            <Tr>
                                <Th className="pl-4">Feature</Th>
                                <Th className="text-right">Uses</Th>
                                <Th className="pr-4">By source</Th>
                            </Tr>
                        </Thead>
                        <Tbody>
                            {features.map((row) => (
                                <Tr key={row.feature}>
                                    <Td className="pl-4 font-mono text-[12px]">{row.feature}</Td>
                                    <Td className="tnum text-right font-medium">{formatCount(row.count)}</Td>
                                    <Td className="tnum pr-4 text-muted">{bySource(row.sources) || '–'}</Td>
                                </Tr>
                            ))}
                        </Tbody>
                    </Table>
                </TableScroll>
            ) : (
                <Empty>No feature usage recorded in this period.</Empty>
            )}
        </Section>
    );
}
