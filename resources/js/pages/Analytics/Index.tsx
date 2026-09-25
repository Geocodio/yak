import { Head, router } from '@inertiajs/react';
import { cn, Menu, PageHeader } from '@geocodio/console-ui';
import { ChevronDown, Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { EventExplorer } from '@/components/analytics/EventExplorer';
import { FailuresChart } from '@/components/analytics/FailuresChart';
import { FeaturesTable } from '@/components/analytics/FeaturesTable';
import { FunnelChart } from '@/components/analytics/FunnelChart';
import { HeadlineTiles } from '@/components/analytics/HeadlineTiles';
import { LatencyChart } from '@/components/analytics/LatencyChart';
import { OutliersTable } from '@/components/analytics/OutliersTable';
import { QueueChart } from '@/components/analytics/QueueChart';
import { ReviewQuality } from '@/components/analytics/ReviewQuality';
import { RunsByKind } from '@/components/analytics/RunsByKind';
import { StagesTable } from '@/components/analytics/StagesTable';
import { ThroughputChart } from '@/components/analytics/ThroughputChart';
import { ToolUsage } from '@/components/analytics/ToolUsage';
import { WebhooksSection } from '@/components/analytics/WebhooksSection';
import { analytics } from '@/routes';
import type { AnalyticsFilters, AnalyticsPageProps } from '@/types/analytics';
import type { PageProps } from '@/types/shared';

type Props = PageProps<AnalyticsPageProps>;

const PERIOD_LABELS: Record<string, string> = {
    '7d': '7 days',
    '30d': '30 days',
    '90d': '90 days',
};

export default function Index({
    filters,
    headline,
    funnel,
    throughput,
    latency,
    stages,
    outliers,
    failures,
    runsByKind,
    tokens,
    tools,
    features,
    webhooks,
    reviews,
    queues,
    explorer,
}: Props) {
    const navigate = (next: Partial<Pick<AnalyticsFilters, 'period' | 'repo' | 'source' | 'event'>>) => {
        const merged = {
            period: filters.period,
            repo: filters.repo,
            source: filters.source,
            event: filters.event,
            ...next,
        };

        // Only send filters that are actually set. An empty filter sent as a
        // blank query parameter arrives server-side as null, not '', which
        // used to fail validation and bounce the whole request.
        const query = Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== '' && value !== null));

        router.get(analytics.url(), query, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="Analytics" />
            <PageHeader
                crumbs={['Analytics']}
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
                <div className="ml-4 flex shrink-0 items-center gap-0.5 rounded-control bg-panel-2 p-0.5" data-testid="analytics-period">
                    {filters.periods.map((period) => (
                        <button
                            key={period}
                            type="button"
                            onClick={() => navigate({ period: period as AnalyticsFilters['period'] })}
                            className={cn(
                                'h-6 rounded-chip px-2.5 text-[12px] whitespace-nowrap text-muted hover:text-body',
                                filters.period === period && 'bg-panel text-body shadow-card',
                            )}
                        >
                            {PERIOD_LABELS[period] ?? period}
                        </button>
                    ))}
                </div>
            </PageHeader>

            <div className="min-h-0 flex-1 overflow-auto">
                <div className="mx-auto max-w-[1200px] px-4 py-6 sm:px-8">
                    {!filters.enabled && (
                        <div
                            className="mb-4 flex items-start gap-2.5 rounded-card border border-warn/40 bg-warn-soft px-4 py-3 text-[12.5px] leading-relaxed"
                            data-testid="telemetry-disabled-notice"
                        >
                            <Info size={14} className="mt-0.5 shrink-0 text-warn" />
                            <p>
                                <span className="font-medium text-body">Telemetry is off.</span>{' '}
                                <span className="text-muted">
                                    <code className="font-mono text-[12px]">YAK_TELEMETRY_ENABLED=false</code> stops Yak recording runs, events and
                                    timings, so nothing below is being collected. Figures that come straight from tasks and PRs still show; the
                                    rest stays empty until it is turned back on.
                                </span>
                            </p>
                        </div>
                    )}

                    <p className="mb-4 text-[12px] text-muted">
                        {filters.range.start} to {filters.range.end} · {filters.range.days} days · events kept for {filters.retentionDays} days
                    </p>

                    <HeadlineTiles headline={headline} days={filters.range.days} />

                    <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[2fr_3fr]">
                        <FunnelChart funnel={funnel} />
                        <ThroughputChart throughput={throughput} sourceKeys={filters.sources} />
                    </div>

                    <div className="mt-6">
                        <LatencyChart latency={latency} />
                    </div>

                    <div className="mt-6">
                        <StagesTable stages={stages} />
                    </div>

                    <div className="mt-6">
                        <OutliersTable outliers={outliers} />
                    </div>

                    <div className="mt-6">
                        <FailuresChart failures={failures} />
                    </div>

                    <div className="mt-6">
                        <RunsByKind runsByKind={runsByKind} tokens={tokens} />
                    </div>

                    <div className="mt-6">
                        <ToolUsage tools={tools} />
                    </div>

                    <div className="mt-6">
                        <FeaturesTable features={features} />
                    </div>

                    <div className="mt-6">
                        <WebhooksSection webhooks={webhooks} />
                    </div>

                    <div className="mt-6">
                        <ReviewQuality reviews={reviews} />
                    </div>

                    <div className="mt-6">
                        <QueueChart queues={queues} />
                    </div>

                    <div className="mt-6">
                        <EventExplorer explorer={explorer} selected={filters.event} onSelect={(event) => navigate({ event })} />
                    </div>
                </div>
            </div>
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
