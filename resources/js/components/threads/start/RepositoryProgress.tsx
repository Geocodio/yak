import { Check, Loader2, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
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
        <div data-testid="repo-progress" className="flex flex-col gap-1.5 text-[12.5px]">
            <p className="text-muted">Cloning and setting up. The first start takes longer with several repos.</p>
            {(data?.repositories ?? []).map(({ repo, role, status }) => (
                <div key={repo} className="flex items-center gap-2" data-testid="repo-progress-row" data-status={status}>
                    <span className="font-medium text-body">{repo}</span>
                    <span className="text-faint">{role}</span>
                    <span className="ml-auto inline-flex items-center gap-1 text-muted">
                        {status === 'ready' ? <Check size={13} aria-hidden="true" /> : status === 'failed' ? <TriangleAlert size={13} aria-hidden="true" /> : <Loader2 size={13} className="animate-spin" aria-hidden="true" />}
                        {LABELS[status]}
                    </span>
                </div>
            ))}
        </div>
    );
}
