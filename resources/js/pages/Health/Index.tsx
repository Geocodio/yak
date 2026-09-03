import { Deferred, Head, router } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { Loader2, RotateCw } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { HealthRow } from '@/components/health/HealthRow';
import { refresh } from '@/routes/health';
import type { HealthCheckMeta, HealthResultData } from '@/types/health';
import type { PageProps } from '@/types/shared';

type ResultsProp = 'systemResults' | 'channelResults';

type Props = PageProps<{
    systemChecks: HealthCheckMeta[];
    channelChecks: HealthCheckMeta[];
    systemResults?: Record<string, HealthResultData>;
    channelResults?: Record<string, HealthResultData>;
}>;

export default function Index({ systemChecks, channelChecks, systemResults, channelResults }: Props) {
    const [refreshing, setRefreshing] = useState(false);

    const refreshAll = () => {
        setRefreshing(true);
        router.post(
            refresh.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: () => router.reload({ only: ['systemResults', 'channelResults'] }),
                onFinish: () => setRefreshing(false),
            },
        );
    };

    return (
        <>
            <Head title="Health" />
            <PageHeader crumbs={['Health']}>
                <Button
                    variant="tertiary"
                    icon={<RotateCw size={14} />}
                    pending={refreshing}
                    onClick={refreshAll}
                    className="ml-auto"
                    data-testid="health-refresh-all"
                >
                    Refresh all
                </Button>
            </PageHeader>

            <div className="min-h-0 flex-1 overflow-auto p-5">
                <p className="mb-5 max-w-prose text-[13px] leading-relaxed text-muted">
                    Every check runs live against the real dependency, so a full pass takes a few seconds. Channel checks call third-party
                    APIs and are usually the slowest.
                </p>

                <HealthSection title="System" checks={systemChecks} results={systemResults} resultsProp="systemResults" />

                {channelChecks.length > 0 && (
                    <HealthSection title="Channels" checks={channelChecks} results={channelResults} resultsProp="channelResults" />
                )}
            </div>
        </>
    );
}

function HealthSection({
    title,
    checks,
    results,
    resultsProp,
}: {
    title: string;
    checks: HealthCheckMeta[];
    results?: Record<string, HealthResultData>;
    resultsProp: ResultsProp;
}) {
    const headingId = `${title.toLowerCase()}-section-heading`;

    return (
        <section aria-labelledby={headingId} className="mb-8">
            <div className="mb-3 flex items-baseline gap-2 pl-2">
                <h2 id={headingId} className="text-[11px] font-semibold tracking-wider text-faint uppercase">
                    {title}
                </h2>
                <Deferred data={resultsProp} fallback={<PendingCount count={checks.length} />}>
                    <span className="text-[11px] text-faint">{checks.length} checks</span>
                </Deferred>
            </div>
            <div className="overflow-hidden rounded-2xl border border-hair bg-app-elevated shadow-sm">
                {/*
                 * The fallback renders the same rows in a pending state rather
                 * than anonymous skeletons, so a slow section still tells the
                 * user which checks are outstanding.
                 */}
                <Deferred
                    data={resultsProp}
                    fallback={checks.map((meta) => (
                        <HealthRow key={meta.id} meta={meta} result={undefined} resultsProp={resultsProp} />
                    ))}
                >
                    {checks.map((meta) => (
                        <HealthRow key={meta.id} meta={meta} result={results?.[meta.id]} resultsProp={resultsProp} />
                    ))}
                </Deferred>
            </div>
        </section>
    );
}

function PendingCount({ count }: { count: number }) {
    return (
        <span className="flex items-center gap-1.5 text-[11px] text-faint" data-testid="health-section-pending">
            <Loader2 size={11} className="animate-spin" />
            Running {count} checks…
        </span>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
