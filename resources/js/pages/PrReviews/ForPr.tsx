import { Head, useForm } from '@inertiajs/react';
import { Badge, Button } from '@geocodio/console-ui';
import { ExternalLink } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { FindingsBlock } from '@/components/tasks/FindingsBlock';
import { rerun } from '@/routes/pr-reviews/for-pr';
import type { PageProps } from '@/types/shared';
import type { PrReviewEntry, PrReviewForPrSummary } from '@/types/prReviews';

type Props = PageProps<{
    pr: PrReviewForPrSummary;
    reviews: PrReviewEntry[];
}>;

export default function ForPr({ pr, reviews }: Props) {
    const form = useForm({});

    const rerunReview = () => {
        form.post(rerun.url({ repoSlug: pr.repoSlug, prNumber: pr.number }), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`${pr.repoSlug}#${pr.number}`} />
            <PageHeader
                crumbs={['PR Reviews', `${pr.repoSlug}#${pr.number}`]}
                actions={
                    <Button variant="secondary" pending={form.processing} onClick={rerunReview} data-testid="rerun-review">
                        Re-run review
                    </Button>
                }
            />

            <div className="flex items-center gap-3 border-b border-hair px-5 py-3">
                <h1 className="truncate text-[15px] font-medium text-body">{pr.title}</h1>
                <a href={pr.url} target="_blank" rel="noopener" className="flex shrink-0 items-center gap-1 text-[12px] text-accent-text hover:underline">
                    View on GitHub
                    <ExternalLink size={11} />
                </a>
            </div>

            <div className="min-h-0 flex-1 overflow-auto px-5 py-4">
                {reviews.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-5 py-16 text-center text-[13px] text-muted">
                        <p>No reviews yet for this PR.</p>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {reviews.map((review) => (
                            <div key={review.id} data-testid={`pr-review-${review.id}`}>
                                <div className="flex items-center gap-2 text-[12px] text-muted">
                                    <Badge tone={review.scope === 'incremental' ? 'accent' : 'neutral'}>{review.scope}</Badge>
                                    {review.dismissed && <Badge tone="neutral">Dismissed</Badge>}
                                    <span>{review.reviewer}</span>
                                    <span>{review.createdAgo}</span>
                                    <span className="font-mono text-faint">{review.commitSha.slice(0, 10)}</span>
                                </div>
                                <FindingsBlock findings={review.findings} />
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

ForPr.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
