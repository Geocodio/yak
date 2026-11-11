import { cn } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { retry } from '@/routes/threads/restore';
import type { ThreadNotice } from '@/types/threads';

const dot = { pending: 'border border-idle', running: 'bg-brand', done: 'bg-ok', failed: 'bg-fail' } as const;

export function RestoreProgress({ steps, threadId, readonly }: { steps: ThreadNotice['restoreSteps']; threadId: number; readonly: boolean }) {
    const [busy, setBusy] = useState(false);

    return (
        <ul className="mt-3 flex flex-col gap-2" data-testid="restore-progress">
            {steps.map((step) => (
                <li key={step.label} className="flex items-center gap-2 text-[13px]">
                    <span aria-hidden className={cn('inline-block h-2 w-2 shrink-0 rounded-pill', dot[step.status])} />
                    <span className="text-body">{step.label}</span>
                    {step.error && <span className="text-fail">{step.error}</span>}
                    {step.status === 'failed' && !readonly && (
                        <button
                            type="button"
                            data-testid="restore-retry"
                            disabled={busy}
                            onClick={() => router.post(retry.url(threadId), {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                            className="ml-auto rounded-card border border-hair px-2 py-1 text-[12px] disabled:opacity-50"
                        >
                            Retry
                        </button>
                    )}
                </li>
            ))}
        </ul>
    );
}
