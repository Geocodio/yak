import { StatTile } from '@/components/costs/StatTile';
import { formatCount, formatMs, formatPct, formatUsd } from '@/lib/format';
import type { Headline } from '@/types/analytics';

export function HeadlineTiles({ headline, days }: { headline: Headline; days: number }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6" data-testid="analytics-headline">
            <StatTile label="Tasks" value={formatCount(headline.tasks)} sub={`in ${days} days`} hint="Tasks created in the period, excluding setup runs." />
            <StatTile
                label="PRs opened"
                value={formatCount(headline.prsOpened)}
                sub={`${formatCount(headline.merged)} merged · ${formatCount(headline.closedUnmerged)} closed · ${formatCount(headline.openPrs)} open`}
                hint="Root fix tasks that opened a PR of their own."
            />
            <StatTile
                label="Merge rate"
                value={formatPct(headline.mergeRate)}
                sub="of decided PRs"
                hint="Merged as a share of PRs that were merged or closed. Open PRs are not counted yet."
            />
            <StatTile
                label="One-shot rate"
                value={formatPct(headline.oneShotRate)}
                sub="of merged PRs, no follow-up"
                hint="Merged PRs that needed no follow-up task before merging."
            />
            <StatTile
                label="Time to PR"
                value={formatMs(headline.timeToPr.p50)}
                sub={`p90 ${formatMs(headline.timeToPr.p90)} · p99 ${formatMs(headline.timeToPr.p99)}`}
                hint={`Median from request to PR opened, over ${formatCount(headline.timeToPr.n)} PRs.`}
            />
            <StatTile
                label="Time to merge"
                value={formatMs(headline.timeToMerge.p50)}
                sub={`p90 ${formatMs(headline.timeToMerge.p90)} · p99 ${formatMs(headline.timeToMerge.p99)}`}
                hint={`Median from PR opened to merged, over ${formatCount(headline.timeToMerge.n)} PRs.`}
            />
            <StatTile
                label="Cost per merged PR"
                value={formatUsd(headline.costPerMergedPr)}
                sub={`${formatUsd(headline.totalCost)} total`}
                hint="All Claude Code cost in the period divided by merged PRs. Estimated list price, not a bill."
            />
            <StatTile label="Failure rate" value={formatPct(headline.failureRate)} sub="of tasks" hint="Tasks that ended in an error." />
            <StatTile
                label="Human touch-ups"
                value={formatPct(headline.humanTouchRate)}
                sub="of merged PRs"
                hint="Merged PRs a human pushed commits to before merging."
            />
            <StatTile
                label="Reviews"
                value={formatCount(headline.reviews)}
                sub={`${formatPct(headline.reviewActedRate)} acted on`}
                hint="PR reviews Yak submitted, and how often authors acted on the findings."
            />
        </div>
    );
}
