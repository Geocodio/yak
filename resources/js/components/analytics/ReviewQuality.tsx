import { Table, Tbody, Td, Th, Thead, Tr } from '@geocodio/console-ui';
import { Empty, MiniStat, Section } from '@/components/analytics/Section';
import { formatCount, formatMs, formatPct } from '@/lib/format';
import type { ReviewSummary } from '@/types/analytics';

const SEVERITY_LABELS: Record<string, string> = {
    must_fix: 'Must fix',
    should_fix: 'Should fix',
    consider: 'Consider',
};

const RESOLUTION_ORDER = ['fixed', 'still_outstanding', 'untouched', 'withdrawn'];

const RESOLUTION_LABELS: Record<string, string> = {
    fixed: 'Fixed',
    still_outstanding: 'Still outstanding',
    untouched: 'Untouched',
    withdrawn: 'Withdrawn',
};

function labelFor(key: string, labels: Record<string, string>): string {
    return labels[key] ?? key.replace(/_/g, ' ');
}

function KeyedTable({ title, rows }: { title: string; rows: { key: string; label: string; count: number }[] }) {
    return (
        <div className="overflow-x-auto">
            <h3 className="mb-1 text-[12px] font-medium text-muted">{title}</h3>
            <Table className="w-full">
                <Thead>
                    <Tr>
                        <Th>{title}</Th>
                        <Th className="text-right">Findings</Th>
                    </Tr>
                </Thead>
                <Tbody>
                    {rows.map((row) => (
                        <Tr key={row.key}>
                            <Td>{row.label}</Td>
                            <Td className="tnum text-right font-medium">{formatCount(row.count)}</Td>
                        </Tr>
                    ))}
                </Tbody>
            </Table>
        </div>
    );
}

export function ReviewQuality({ reviews }: { reviews: ReviewSummary }) {
    const severityRows = Object.entries(reviews.bySeverity).map(([key, count]) => ({ key, label: labelFor(key, SEVERITY_LABELS), count }));
    const resolutionRows = [...RESOLUTION_ORDER, ...Object.keys(reviews.resolution).filter((key) => !RESOLUTION_ORDER.includes(key))].map((key) => ({
        key,
        label: labelFor(key, RESOLUTION_LABELS),
        count: reviews.resolution[key] ?? 0,
    }));

    return (
        <Section title="Review quality" hint="PR reviews Yak submitted: how many findings, how readers reacted, and whether authors acted on them.">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <MiniStat label="Reviews" value={formatCount(reviews.reviews)} />
                <MiniStat label="Incremental" value={formatCount(reviews.incremental)} />
                <MiniStat label="Findings" value={formatCount(reviews.findings)} />
                <MiniStat label="LGTM rate" value={formatPct(reviews.lgtmRate)} sub="reviews with no findings" />
                <MiniStat label="Reactions" value={`${formatCount(reviews.thumbsUp)} / ${formatCount(reviews.thumbsDown)}`} sub="thumbs up / down" />
                <MiniStat label="Time to review" value={formatMs(reviews.timeToReview.p50)} sub={`p90 ${formatMs(reviews.timeToReview.p90)}`} />
            </div>

            {reviews.reviews === 0 ? (
                <Empty>No reviews in this period.</Empty>
            ) : (
                <>
                    <div className="mt-5 grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <KeyedTable title="By severity" rows={severityRows} />
                        <KeyedTable title="Resolution" rows={resolutionRows} />
                    </div>

                    <div className="mt-5 overflow-x-auto">
                        <h3 className="mb-1 text-[12px] font-medium text-muted">By category</h3>
                        {reviews.byCategory.length > 0 ? (
                            <Table className="w-full">
                                <Thead>
                                    <Tr>
                                        <Th>Category</Th>
                                        <Th className="text-right">Findings</Th>
                                        <Th className="text-right">Thumbs up</Th>
                                        <Th className="text-right">Thumbs down</Th>
                                    </Tr>
                                </Thead>
                                <Tbody>
                                    {reviews.byCategory.map((row) => (
                                        <Tr key={row.category}>
                                            <Td>{row.category}</Td>
                                            <Td className="tnum text-right font-medium">{formatCount(row.findings)}</Td>
                                            <Td className="tnum text-right text-muted">{formatCount(row.thumbsUp)}</Td>
                                            <Td className="tnum text-right text-muted">{formatCount(row.thumbsDown)}</Td>
                                        </Tr>
                                    ))}
                                </Tbody>
                            </Table>
                        ) : (
                            <p className="py-6 text-center text-[12px] text-muted">No findings to categorise.</p>
                        )}
                    </div>
                </>
            )}
        </Section>
    );
}
