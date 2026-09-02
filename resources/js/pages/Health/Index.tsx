import { Deferred, Head, router } from '@inertiajs/react';
import { Button, Skeleton } from '@geocodio/console-ui';
import { RotateCw } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { HealthRow } from '@/components/health/HealthRow';
import { refresh } from '@/routes/health';
import type { HealthCheckMeta, HealthResultData } from '@/types/health';
import type { PageProps } from '@/types/shared';

type Props = PageProps<{
    systemChecks: HealthCheckMeta[];
    channelChecks: HealthCheckMeta[];
    results?: Record<string, HealthResultData>;
}>;

export default function Index({ systemChecks, channelChecks, results }: Props) {
    const [refreshing, setRefreshing] = useState(false);

    const refreshAll = () => {
        setRefreshing(true);
        router.post(
            refresh.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: () => router.reload({ only: ['results'] }),
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
                <HealthSection title="System" checks={systemChecks} results={results} />

                {channelChecks.length > 0 && <HealthSection title="Channels" checks={channelChecks} results={results} />}
            </div>
        </>
    );
}

function HealthSection({ title, checks, results }: { title: string; checks: HealthCheckMeta[]; results?: Record<string, HealthResultData> }) {
    const headingId = `${title.toLowerCase()}-section-heading`;

    return (
        <section aria-labelledby={headingId} className="mb-8">
            <h2 id={headingId} className="mb-3 pl-2 text-[11px] font-semibold tracking-wider text-faint uppercase">
                {title}
            </h2>
            <div className="overflow-hidden rounded-2xl border border-hair bg-app-elevated shadow-sm">
                <Deferred data="results" fallback={checks.map((meta) => <Skeleton key={meta.id} className="m-2 h-8" />)}>
                    {checks.map((meta) => (
                        <HealthRow key={meta.id} meta={meta} result={results?.[meta.id]} />
                    ))}
                </Deferred>
            </div>
        </section>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
