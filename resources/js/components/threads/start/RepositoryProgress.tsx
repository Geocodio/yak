import { cn } from '@geocodio/console-ui';
import { Check, Loader2, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { RepositoryAvatar } from '@/components/threads/RepositoryAvatar';
import { progress } from '@/routes/threads/repositories';
import type { RepositoryProgressData } from '@/types/threads';

const LABELS = { queued: 'Queued', cloning: 'Cloning', setup: 'Running setup', ready: 'Ready', failed: 'Setup failed' } as const;

/**
 * Per-repository clone and setup state of a thread that was just started.
 * Polls the progress endpoint (JSON, not an Inertia page) and calls
 * `onReady` once every repository is ready.
 */
export function RepositoryProgress({ threadId, onReady }: { threadId: number; onReady: () => void }) {
    const [data, setData] = useState<RepositoryProgressData | null>(null);

    useEffect(() => {
        let stopped = false;
        const poll = async () => {
            try {
                const response = await fetch(progress.url(threadId), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (response.ok && !stopped) {
                    const next: RepositoryProgressData = await response.json();
                    setData(next);
                    if (next.isReady) {
                        onReady();
                        return;
                    }
                }
            } catch {
                // A dropped poll is retried on the next tick.
            }
            if (!stopped) {
                timer = window.setTimeout(poll, 1500);
            }
        };
        let timer = window.setTimeout(poll, 0);
        return () => {
            stopped = true;
            window.clearTimeout(timer);
        };
    }, [threadId, onReady]);

    return (
        <div data-testid="repo-progress" className="flex flex-wrap items-center gap-2 text-[12.5px]">
            <span className="font-medium text-muted">Repositories</span>
            <span className="text-muted">Cloning and setting up. The first start takes longer with several repos.</span>
            {(data?.repositories ?? []).map(({ repo, role, status }) => (
                <span
                    key={repo}
                    className={cn('inline-flex h-8 items-center gap-1.5 rounded-control border px-2.5 font-medium text-body', status === 'failed' ? 'border-fail' : 'border-hair')}
                    title={LABELS[status]}
                    aria-label={`${repo}, ${role}: ${LABELS[status]}`}
                    data-testid="repo-progress-row"
                    data-status={status}
                >
                    <RepositoryAvatar repo={repo} />
                    {repo}
                    <span className="text-[11px] text-faint">{role}</span>
                    {status === 'ready' ? <Check size={13} aria-hidden="true" /> : status === 'failed' ? <TriangleAlert size={13} className="text-fail" aria-hidden="true" /> : <Loader2 size={13} className="animate-spin" aria-hidden="true" />}
                    {status === 'failed' && <span className="text-[11px] text-fail">{LABELS.failed}</span>}
                </span>
            ))}
        </div>
    );
}
