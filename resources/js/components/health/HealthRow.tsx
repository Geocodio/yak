import { router } from '@inertiajs/react';
import { IconButton, Skeleton, StatusPill } from '@geocodio/console-ui';
import { HelpCircle, RotateCw } from 'lucide-react';
import { useState } from 'react';
import check from '@/routes/health/check';
import { HEALTH_TONE_LABELS, type HealthCheckMeta, type HealthResultData } from '@/types/health';

type Props = {
    meta: HealthCheckMeta;
    result: HealthResultData | undefined;
};

/**
 * One health check row. `result` is `undefined` while the page's single
 * `results` deferred prop hasn't resolved yet -- the parent renders this
 * inside a `<Deferred data="results">` and passes the resolved map down,
 * so every row skeletons together rather than independently (see
 * `HealthController::allResults()` for why the results can't be deferred
 * per row).
 */
export function HealthRow({ meta, result }: Props) {
    const [refreshing, setRefreshing] = useState(false);

    const refresh = () => {
        setRefreshing(true);
        router.post(
            check.refresh.url(meta.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => router.reload({ only: ['results'] }),
                onFinish: () => setRefreshing(false),
            },
        );
    };

    return (
        <div className="flex items-center gap-4 border-b border-hair px-8 py-5 last:border-b-0 hover:bg-app/60" data-testid={`health-row-${meta.id}`}>
            {!result ? (
                <Skeleton className="h-8 w-full" />
            ) : (
                <>
                    <StatusPill tone={result.status} label={HEALTH_TONE_LABELS[result.status]} />
                    <div className="min-w-0 flex-1">
                        <div className="mb-0.5 text-[13px] font-medium text-body">{meta.name}</div>
                        <div className="text-[12px] text-muted">{result.message}</div>
                    </div>
                    {result.actionUrl && (
                        <a
                            href={result.actionUrl}
                            className="shrink-0 rounded-pill bg-accent px-3 py-1.5 text-[12px] font-medium text-accent-contrast"
                        >
                            {result.actionLabel}
                        </a>
                    )}
                </>
            )}

            {meta.docsUrl && (
                <a
                    href={meta.docsUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="shrink-0 text-faint hover:text-body"
                    title={`Docs for ${meta.name}`}
                    aria-label={`Docs for ${meta.name}`}
                >
                    <HelpCircle size={14} />
                </a>
            )}

            <IconButton label={`Refresh ${meta.name}`} onClick={refresh} className={refreshing ? 'animate-spin' : undefined}>
                <RotateCw size={14} />
            </IconButton>
        </div>
    );
}
