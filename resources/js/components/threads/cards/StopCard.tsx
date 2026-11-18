import { Button } from '@geocodio/console-ui';
import { OctagonX, Wrench } from 'lucide-react';
import { useState } from 'react';
import { useRouterAction } from '@/lib/useRouterAction';
import { retry } from '@/routes/threads';
import type { ThreadActions, ThreadStopped } from '@/types/threads';

const TITLES: Record<ThreadStopped['kind'], string> = {
    needs_you: 'Yak needs you to go on',
    yak_retrying: 'Yak is retrying',
    yak_stopped: 'Yak could not finish this change',
    cancelled: 'This request was cancelled',
};

/** Why the work stopped, in plain words, with Retry, Move to another repository and the technical detail one click away. */
export function StopCard({ threadId, stopped, actions, onMove, onShowLog, title }: { title?: string; threadId: string; stopped: ThreadStopped; actions: ThreadActions; onMove: () => void; onShowLog: (() => void) | null }) {
    const action = useRouterAction();
    const [error, setError] = useState<string | null>(null);
    const [isDetailShown, setIsDetailShown] = useState(false);
    const isCalm = stopped.kind === 'yak_retrying' || stopped.kind === 'needs_you';

    const retryNow = () =>
        action.run('retry', 'post', retry.url(threadId), {}, {
            onStart: () => setError(null),
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Yak could not retry. Try again.'),
        });

    return (
        <section className={`rounded-card px-4 py-4 ${isCalm ? 'bg-warn-soft' : 'bg-fail-soft'}`} aria-labelledby="stop-title" data-testid="card-stop" data-kind={stopped.kind}>
            <h2 id="stop-title" className="flex items-center gap-2 text-[15px] font-semibold">
                <OctagonX className={`size-4 ${isCalm ? 'text-warn' : 'text-fail'}`} aria-hidden />
                {title ?? TITLES[stopped.kind]}
            </h2>
            <p className="mt-1 text-[13px] leading-normal" data-testid="stop-summary">{stopped.summary}</p>
            <p className="mt-1 text-[12.5px] leading-normal text-muted" data-testid="stop-next-step">{stopped.nextStep}</p>
            {stopped.detail !== null && (
                <details className="mt-2 text-[12px]" data-testid="stop-detail">
                    <summary className="w-fit cursor-pointer text-muted">Technical detail</summary>
                    <pre className="mt-1 max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-control bg-panel-2 p-2 font-mono text-[11px]">{stopped.detail}</pre>
                </details>
            )}
            {onShowLog === null && isDetailShown && (
                <pre className="mt-2 max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-control bg-panel-2 p-2 font-mono text-[11px]" data-testid="stop-inline-detail">{stopped.detail ?? stopped.summary}</pre>
            )}
            {error !== null && <p role="alert" id="stop-error" className="mt-3 rounded-control bg-fail-soft px-3 py-2 text-[13px]" data-testid="stop-error">{error}</p>}
            <div className="mt-3 flex flex-wrap gap-2 max-sm:flex-col">
                {actions.canRetry && (
                    <Button variant="primary" className="max-sm:h-11 max-sm:w-full" icon={<Wrench size={13} />} pending={action.isPending('retry')} pendingLabel="Retrying…" disabled={action.busy} onClick={retryNow} aria-describedby={error !== null ? 'stop-error' : undefined} data-testid="stop-retry">
                        Retry
                    </Button>
                )}
                {actions.canMove && (
                    <Button className="max-sm:h-11 max-sm:w-full" onClick={onMove} disabled={action.busy} data-testid="stop-move">Move to another repository</Button>
                )}
                {stopped.kind !== 'cancelled' && (
                    <Button className="max-sm:h-11 max-sm:w-full" onClick={onShowLog ?? (() => setIsDetailShown((shown) => !shown))} data-testid="stop-show-log">
                        {onShowLog !== null ? 'Show the failing step' : isDetailShown ? 'Hide the details' : 'Show the details'}
                    </Button>
                )}
            </div>
        </section>
    );
}
