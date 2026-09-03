import { Head, router } from '@inertiajs/react';
import { Menu, Table, Tbody, Td, Th, Thead, Tr, cn } from '@geocodio/console-ui';
import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { SpendChart } from '@/components/costs/SpendChart';
import { StatTile } from '@/components/costs/StatTile';
import { costs } from '@/routes';
import type { ApiSpendRow, BreakdownRow, ChartData, CostFilters, CostSummary, MergeRateRow, VideoSummary } from '@/types/costs';
import type { PageProps } from '@/types/shared';

type Props = PageProps<{
    summary: CostSummary;
    videoSummary: VideoSummary;
    chart: ChartData;
    breakdown: BreakdownRow[];
    apiSpend: ApiSpendRow[];
    mergeRate: MergeRateRow[];
    filters: CostFilters;
}>;

const PERIODS: { key: CostFilters['period']; label: string }[] = [
    { key: 'daily', label: 'Daily' },
    { key: 'weekly', label: 'Weekly' },
    { key: 'monthly', label: 'Monthly' },
];

const SOURCE_COLUMNS = ['slack', 'linear', 'sentry'];

const PERIOD_TITLE: Record<CostFilters['period'], string> = {
    daily: 'Daily',
    weekly: 'Weekly',
    monthly: 'Monthly',
};

export default function Index({ summary, videoSummary, chart, breakdown, apiSpend, mergeRate, filters }: Props) {
    const navigate = (next: Partial<Pick<CostFilters, 'period' | 'repo' | 'source'>>) => {
        const merged = {
            period: filters.period,
            repo: filters.repo,
            source: filters.source,
            ...next,
        };

        // Only send filters that are actually set. An empty filter sent as a
        // blank query parameter arrives server-side as null, not '', which
        // used to fail validation and bounce the whole request.
        const query = Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== '' && value !== null));

        router.get(costs.url(), query, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="Costs" />
            <PageHeader
                crumbs={['Costs']}
                actions={
                    <>
                        <Menu
                            trigger={
                                <span className="flex items-center gap-1.5 text-[12px]">
                                    {filters.repo || 'All repos'}
                                    <ChevronDown size={12} className="text-faint" />
                                </span>
                            }
                            className="h-7 rounded-pill px-2.5"
                            items={[
                                { key: '__all__', label: 'All repos', checked: filters.repo === '', onSelect: () => navigate({ repo: '' }) },
                                ...filters.repos.map((repo) => ({
                                    key: repo,
                                    label: repo,
                                    checked: repo === filters.repo,
                                    onSelect: () => navigate({ repo }),
                                })),
                            ]}
                        />
                        <Menu
                            trigger={
                                <span className="flex items-center gap-1.5 text-[12px]">
                                    {filters.source || 'All sources'}
                                    <ChevronDown size={12} className="text-faint" />
                                </span>
                            }
                            className="h-7 rounded-pill px-2.5"
                            items={[
                                { key: '__all__', label: 'All sources', checked: filters.source === '', onSelect: () => navigate({ source: '' }) },
                                ...filters.sources.map((source) => ({
                                    key: source,
                                    label: source,
                                    checked: source === filters.source,
                                    onSelect: () => navigate({ source }),
                                })),
                            ]}
                        />
                    </>
                }
            >
                <div className="ml-4 flex items-center gap-0.5 rounded-control bg-panel-2 p-0.5">
                    {PERIODS.map((period) => (
                        <button
                            key={period.key}
                            type="button"
                            onClick={() => navigate({ period: period.key })}
                            className={cn(
                                'h-6 rounded-chip px-2.5 text-[12px] text-muted hover:text-body',
                                filters.period === period.key && 'bg-panel text-body shadow-card',
                            )}
                        >
                            {period.label}
                        </button>
                    ))}
                </div>
            </PageHeader>

            <div className="min-h-0 flex-1 overflow-auto">
                <div className="mx-auto max-w-[1200px] px-8 py-6">
                    <p className="mb-4 max-w-prose text-[12.5px] leading-relaxed text-muted" data-testid="cost-basis-note">
                        <span className="font-medium text-body">Claude Code figures are estimates, not a bill.</span> They are the
                        list price of the tokens each task used, as reported by the agent. If Yak runs on a Claude subscription, that
                        work is covered by the subscription and almost none of it is charged to you. Claude Code does not report
                        whether a given run was covered, so treat these numbers as relative usage, not spend. The API-billed figure
                        below is different: it is real usage against your API key and does appear on your Anthropic invoice.
                    </p>

                    <div className="grid grid-cols-7 gap-3">
                        <StatTile
                            label="Claude Code (est. list price)"
                            value={`$${summary.claudeCode.amount.toFixed(2)}`}
                            sub={`${summary.claudeCode.tasks} tasks`}
                            hint="What these tokens would cost at API list price. Covered by the subscription, so this is usually not money you were charged."
                        />
                        <StatTile
                            label="API-billed spend"
                            value={`$${summary.apiSpend.amount.toFixed(2)}`}
                            sub={`${summary.apiSpend.calls} calls`}
                            hint="Actual Anthropic API usage (notification copy, repo routing). Appears on your invoice."
                        />
                        <StatTile label="Tasks" value={String(summary.taskCount)} sub="in period" />
                        <StatTile label="Avg cost" value={`$${summary.avgCost.toFixed(2)}`} sub="per task" />
                        <StatTile label="Avg duration" value={summary.avgDuration} sub="per task" />
                        <StatTile label="Success rate" value={`${summary.successRate}%`} sub="of tasks" />
                        <StatTile label="Clarification rate" value={`${summary.clarificationRate}%`} sub="of tasks" />
                    </div>

                    <div className="mt-3 grid grid-cols-3 gap-3">
                        <StatTile
                            label="Videos rendered"
                            value={String(videoSummary.rendered)}
                            sub={`${videoSummary.failed} failed`}
                            hint="Walkthrough videos rendered for PRs in this period."
                        />
                        <StatTile
                            label="Avg render time"
                            value={videoSummary.avgRenderTime}
                            sub="per video"
                            hint="Average Remotion render time per successful video."
                        />
                        <StatTile
                            label="Video output"
                            value={`${videoSummary.outputMb.toFixed(1)} MB`}
                            sub={`rendered cuts · ${videoSummary.voiceoverCredits.toLocaleString()} voiceover credits`}
                            hint="Total size of rendered cuts in this period."
                        />
                    </div>

                    <section className="mt-6 rounded-card border border-hair bg-panel p-4 shadow-card">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-[13px] font-semibold">Spend · {filters.period} view</h2>
                            <div className="flex items-center gap-4 text-[11px] text-muted">
                                <span className="flex items-center gap-1.5">
                                    <span className="h-2 w-2 rounded-[2px] border border-accent bg-accent-soft" />
                                    Claude Code (est. list price)
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="h-2 w-2 rounded-[2px] bg-info" />
                                    API-billed
                                </span>
                            </div>
                        </div>
                        <SpendChart buckets={chart.buckets} max={chart.max} />
                    </section>

                    <div className={cn('mt-6 grid gap-6', mergeRate.length > 0 ? 'grid-cols-[3fr_2fr]' : 'grid-cols-1')}>
                        <section className="overflow-hidden rounded-card border border-hair bg-panel shadow-card">
                            <div className="flex items-baseline justify-between border-b border-hair px-4 py-2.5">
                                <h2 className="text-[13px] font-semibold">Claude Code · {filters.period} breakdown</h2>
                                <span className="text-[11px] text-faint">est. list price, not billed</span>
                            </div>
                            {breakdown.length > 0 ? (
                                <Table className="w-full">
                                    <Thead>
                                        <Tr>
                                            <Th className="pl-4">Date</Th>
                                            <Th className="text-right">Tasks</Th>
                                            {SOURCE_COLUMNS.map((source) => (
                                                <Th key={source} className="text-right capitalize">
                                                    {source}
                                                </Th>
                                            ))}
                                            <Th className="pr-4 text-right">Total</Th>
                                        </Tr>
                                    </Thead>
                                    <Tbody>
                                        {breakdown.map((row) => (
                                            <Tr key={row.date}>
                                                <Td className="pl-4">{row.date}</Td>
                                                <Td className="tnum text-right text-muted">{row.tasks}</Td>
                                                {SOURCE_COLUMNS.map((source) => (
                                                    <Td key={source} className="tnum text-right text-muted">
                                                        {row.sources[source] !== undefined ? `$${row.sources[source].toFixed(2)}` : '—'}
                                                    </Td>
                                                ))}
                                                <Td className="tnum pr-4 text-right font-medium">${row.total.toFixed(2)}</Td>
                                            </Tr>
                                        ))}
                                    </Tbody>
                                </Table>
                            ) : (
                                <p className="px-4 py-12 text-center text-[13px] text-muted">No cost data for this period.</p>
                            )}
                        </section>

                        {mergeRate.length > 0 && (
                            <section className="overflow-hidden rounded-card border border-hair bg-panel shadow-card">
                                <div className="flex items-baseline justify-between border-b border-hair px-4 py-2.5">
                                    <h2 className="text-[13px] font-semibold">PR merge rate</h2>
                                </div>
                                <Table className="w-full">
                                    <Thead>
                                        <Tr>
                                            <Th className="pl-4">Repo</Th>
                                            <Th className="text-right">PRs</Th>
                                            <Th className="text-right">Merged</Th>
                                            <Th className="text-right">Closed</Th>
                                            <Th className="pr-4 text-right">Rate</Th>
                                        </Tr>
                                    </Thead>
                                    <Tbody>
                                        {mergeRate.map((row) => (
                                            <Tr key={row.repo}>
                                                <Td className="pl-4 font-mono text-[12px]">{row.repo}</Td>
                                                <Td className="tnum text-right text-muted">{row.totalPrs}</Td>
                                                <Td className="tnum text-right text-muted">{row.merged}</Td>
                                                <Td className="tnum text-right text-muted">{row.closed}</Td>
                                                <Td className="tnum pr-4 text-right">
                                                    <span
                                                        className={cn(
                                                            'rounded-chip px-1.5 py-0.5 text-[11px]',
                                                            row.rate >= 75 ? 'bg-ok-soft text-ok' : 'bg-warn-soft text-warn',
                                                        )}
                                                    >
                                                        {row.rate}%
                                                    </span>
                                                </Td>
                                            </Tr>
                                        ))}
                                    </Tbody>
                                </Table>
                            </section>
                        )}
                    </div>

                    <section className="mt-6 overflow-hidden rounded-card border border-hair bg-panel shadow-card" data-testid="api-spend-breakdown">
                        <div className="flex items-baseline justify-between border-b border-hair px-4 py-2.5">
                            <h2 className="text-[13px] font-semibold">API Spend -- {PERIOD_TITLE[filters.period]} Breakdown</h2>
                            <span className="text-[11px] text-faint">actual Anthropic billing</span>
                        </div>
                        {apiSpend.length > 0 ? (
                            <Table className="w-full">
                                <Thead>
                                    <Tr>
                                        <Th className="pl-4">Date</Th>
                                        <Th className="text-right">Calls</Th>
                                        <Th className="pr-4 text-right">Total</Th>
                                    </Tr>
                                </Thead>
                                <Tbody>
                                    {apiSpend.map((row) => (
                                        <Tr key={row.date}>
                                            <Td className="pl-4">{row.date}</Td>
                                            <Td className="tnum text-right text-muted">{row.calls}</Td>
                                            <Td className="tnum pr-4 text-right font-medium">${row.total.toFixed(4)}</Td>
                                        </Tr>
                                    ))}
                                </Tbody>
                            </Table>
                        ) : (
                            <p className="px-4 py-12 text-center text-[13px] text-muted">No API calls recorded for this period.</p>
                        )}
                    </section>

                    <p className="mt-6 text-[11px] leading-relaxed text-faint">
                        API-billed spend covers notification copy and repo routing through the AI SDK. That is the only figure on this page
                        that bills your API key.
                    </p>
                </div>
            </div>
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
