import { Spinner, cn } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { retry } from '@/routes/threads/restore';
import type { ThreadNotice } from '@/types/threads';

type Step = ThreadNotice['restoreSteps'][number];

export function RetryRestoreButton({ threadId, testId, className, children = 'Retry from this step' }: { threadId: string; testId: string; className?: string; children?: string }) {
    const [busy, setBusy] = useState(false);

    return (
        <button
            type="button"
            data-testid={testId}
            disabled={busy}
            onClick={() => router.post(retry.url(threadId), {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
            className={cn('cursor-pointer rounded-card bg-accent px-3 py-1.5 text-[13px] font-medium text-white disabled:opacity-50', className)}
        >
            {children}
        </button>
    );
}

function StepMarker({ status }: { status: Step['status'] }) {
    if (status === 'done') {
        return <Check className="size-4 shrink-0 text-ok" aria-hidden />;
    }
    if (status === 'running') {
        return <Spinner size="sm" />;
    }

    return <span aria-hidden className={cn('inline-block size-2.5 shrink-0 rounded-pill', status === 'failed' ? 'bg-fail' : 'border border-idle')} />;
}

const WORD = { pending: 'Waiting', running: 'Running', done: null, failed: 'Failed' } as const;

export function RestoreProgress({ steps }: { steps: Step[] }) {
    return (
        <ol className="mt-3 flex flex-col gap-2.5" data-testid="restore-progress">
            {steps.map((step) => (
                <li key={step.key} className="flex items-start gap-2.5 text-[13px]" data-status={step.status}>
                    <span className="mt-0.5 flex size-4 shrink-0 items-center justify-center">
                        <StepMarker status={step.status} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="flex items-baseline gap-2">
                            <span className={cn('text-body', step.status === 'pending' && 'text-muted')}>{step.label}</span>
                            <span className="ml-auto shrink-0 text-[12px] text-muted">
                                {step.durationSeconds !== null ? `${Math.round(step.durationSeconds)} s` : WORD[step.status]}
                            </span>
                        </div>
                        <p className="text-[12px] text-muted">{step.detail}</p>
                        {step.status === 'running' && step.outputTail.length > 0 && (
                            <p className="mt-1 break-words font-mono text-[12px] text-muted">{step.outputTail[step.outputTail.length - 1]}</p>
                        )}
                    </div>
                </li>
            ))}
        </ol>
    );
}
