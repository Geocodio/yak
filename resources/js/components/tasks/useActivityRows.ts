import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ActivityData, ActivityRow } from '@/types/tasks';

export const ACTIVITY_WINDOW = 200;
export const ACTIVITY_ROW_CAP = 2000;

/**
 * The page props the poll refreshes. Everything except the initial activity
 * window and the on-demand props (`activityOlder`, `transcriptEntry`), plus
 * `activityTail`, which the server fills from the `after` cursor the poll
 * sends.
 */
export const POLLED_PROPS = [
    'task',
    'thread',
    'runs',
    'attempts',
    'activitySummary',
    'progress',
    'media',
    'walkthrough',
    'deployment',
    'findings',
    'composer',
    'debug',
    'actions',
    'pollInterval',
    'transcriptLogId',
    'activityTail',
] as const;

function mergeRows(existing: ActivityRow[], incoming: ActivityRow[], position: 'before' | 'after'): ActivityRow[] {
    const known = new Set(existing.map((row) => row.id));
    const fresh = incoming.filter((row) => !known.has(row.id));
    if (fresh.length === 0) {
        return existing;
    }
    return position === 'before' ? [...fresh, ...existing] : [...existing, ...fresh];
}

/**
 * Owns the activity rows the page holds in memory: the initial window,
 * older pages prepended on request, and the poll's tail appended as it
 * arrives. The tail is what grows on a long run, so the cap applies there:
 * once the rows pass ACTIVITY_ROW_CAP the oldest are dropped and `hasOlder`
 * turns back on, so the live tail is always intact and the dropped rows can
 * be paged back in. Loading older rows never drops anything; the list only
 * stops loading them automatically once the cap is reached (`capped`), and
 * the caller decides whether to offer a manual load beyond it.
 */
export function useActivityRows({
    runKey,
    activity,
    activityOlder,
    activityTail,
}: {
    runKey: string;
    activity: ActivityData;
    activityOlder?: ActivityRow[];
    activityTail?: ActivityRow[];
}) {
    const [rows, setRows] = useState<ActivityRow[]>(activity.rows);
    const [hasOlder, setHasOlder] = useState(activity.hasOlder);
    const [loadingOlder, setLoadingOlder] = useState(false);
    const latestIdRef = useRef<number | null>(activity.rows.length > 0 ? activity.rows[activity.rows.length - 1].id : null);
    const seenRunKey = useRef(runKey);

    // A run or attempt switch replaces everything; the old rows belong to
    // another log.
    useEffect(() => {
        if (seenRunKey.current === runKey) {
            return;
        }
        seenRunKey.current = runKey;
        setRows(activity.rows);
        setHasOlder(activity.hasOlder);
        latestIdRef.current = activity.rows.length > 0 ? activity.rows[activity.rows.length - 1].id : null;
    }, [runKey, activity]);

    useEffect(() => {
        if (!activityTail || activityTail.length === 0) {
            return;
        }
        setRows((current) => {
            const next = mergeRows(current, activityTail, 'after');
            if (next.length > ACTIVITY_ROW_CAP) {
                setHasOlder(true);
                return next.slice(next.length - ACTIVITY_ROW_CAP);
            }
            return next;
        });
        latestIdRef.current = activityTail[activityTail.length - 1].id;
        // A full window means the server had more; ask again right away.
        if (activityTail.length >= ACTIVITY_WINDOW) {
            router.reload({ only: ['activityTail', 'activitySummary'], data: { after: latestIdRef.current }, preserveUrl: true });
        }
    }, [activityTail]);

    useEffect(() => {
        if (!activityOlder) {
            return;
        }
        setLoadingOlder(false);
        if (activityOlder.length === 0) {
            setHasOlder(false);
            return;
        }
        setRows((current) => mergeRows(current, activityOlder, 'before'));
        if (activityOlder.length < ACTIVITY_WINDOW) {
            setHasOlder(false);
        }
    }, [activityOlder]);

    const loadOlder = useCallback(() => {
        if (loadingOlder || !hasOlder || rows.length === 0) {
            return;
        }
        setLoadingOlder(true);
        router.reload({
            only: ['activityOlder'],
            data: { before: rows[0].id },
            preserveUrl: true,
            onFinish: () => setLoadingOlder(false),
        });
    }, [loadingOlder, hasOlder, rows]);

    const capped = rows.length >= ACTIVITY_ROW_CAP;

    return { rows, hasOlder, loadingOlder, loadOlder, latestId: latestIdRef.current, capped };
}
