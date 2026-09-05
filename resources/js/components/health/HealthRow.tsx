import { router } from '@inertiajs/react';
import { IconButton, StatusPill } from '@geocodio/console-ui';
import { HelpCircle, Loader2, RotateCw } from 'lucide-react';
import { useState } from 'react';
import check from '@/routes/health/check';
import { HEALTH_TONE_LABELS, type HealthCheckMeta, type HealthResultData } from '@/types/health';

type Props = {
    meta: HealthCheckMeta;
    result: HealthResultData | undefined;
    /**
     * The deferred prop holding this row's section. A single-row refresh
     * reloads only that section, so clearing one channel's cache doesn't
     * re-request the system checks (see `HealthController::allResults()`).
     */
    resultsProp: 'systemResults' | 'channelResults';
};

/**
 * One health check row. `result` is `undefined` while the section's deferred
 * prop hasn't resolved yet; the row still shows the check's name so a slow
 * page reads as "working on it" rather than as an anonymous grey bar.
 */
export function HealthRow({ meta, result, resultsProp }: Props) {
    const [refreshing, setRefreshing] = useState(false);

    const refresh = () => {
        setRefreshing(true);
        router.post(
            check.refresh.url(meta.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => router.reload({ only: [resultsProp] }),
                onFinish: () => setRefreshing(false),
            },
        );
    };

    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-hair px-4 py-5 last:border-b-0 hover:bg-app/60 sm:px-8" data-testid={`health-row-${meta.id}`}>
            {!result ? (
                <>
                    <span
                        className="flex items-center gap-1.5 text-[12px] text-faint"
                        data-testid={`health-row-pending-${meta.id}`}
                    >
                        <Loader2 size={13} className="animate-spin" />
                        Checking
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="mb-0.5 text-[13px] font-medium text-muted">{meta.name}</div>
                    </div>
                </>
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

            {result && (
                <IconButton label={`Refresh ${meta.name}`} onClick={refresh} className={refreshing ? 'animate-spin' : undefined}>
                    <RotateCw size={14} />
                </IconButton>
            )}
        </div>
    );
}
