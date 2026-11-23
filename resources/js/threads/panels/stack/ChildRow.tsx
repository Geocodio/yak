import { Button } from '@geocodio/console-ui';
import { Link, router } from '@inertiajs/react';
import { ChildStateDot, CHILD_STATE_LABEL } from '@/components/threads/stack/ChildStateDot';
import { show } from '@/routes/threads';
import { drop, split, takeOver } from '@/routes/threads/stack';
import { childDisplayState } from '@/threads/panels/agents/agentsState';
import type { StackChild } from '@/types/stack';

export type WaitingHelp = { awake: number; current: number; max: number; canRaise: boolean; isSaving: boolean; onRaise: () => void };

const capitalize = (word: string): string => word.charAt(0).toUpperCase() + word.slice(1);

/** Why a sub-thread waits for a free spot, in the person's words. */
export function waitingReason(awake: number): string {
    if (awake < 1) {
        return 'Waiting for a free spot. This one starts in a moment.';
    }

    return `Waiting for a free spot. ${awake} ${awake === 1 ? 'sub-thread is' : 'sub-threads are'} already running, so this one starts when ${awake === 1 ? 'it' : 'one of them'} finishes.`;
}

export function ChildRow({ child, waiting, onChanged }: { child: StackChild; waiting: WaitingHelp; onChanged: () => void }) {
    const act = (route: typeof takeOver) => router.post(route.url(child.id), {}, { preserveScroll: true, onFinish: onChanged });
    const state = childDisplayState(child.state, child.kind);
    const detail = state === 'working' ? child.step : state === 'done' ? child.resultSummary : null;
    const stateLabel = child.takenOver ? 'Taken over' : CHILD_STATE_LABEL[state];
    const isQueued = state === 'queued';

    return (
        <li className={isQueued ? 'bg-panel-2 px-3 py-2.5' : 'px-3 py-2.5'} data-testid={`stack-child-${child.id}`} data-state={state}>
            <div className="flex items-center gap-2">
                <ChildStateDot state={state} />
                <Link href={show.url(child.id)} className="min-w-0 truncate text-[13px] font-medium text-body hover:underline">{child.name}</Link>
            </div>
            <p className="mt-0.5 text-[12px] text-muted" data-testid={`stack-child-${child.id}-line`}>
                {[capitalize(child.kind), child.repo, stateLabel].filter((part) => part).join(' · ')}
                {child.messagesPaused ? ' · messages paused' : ''}
            </p>
            {child.pullRequestUrl && (
                <p className="mt-0.5 text-[12px] text-muted">
                    <a href={child.pullRequestUrl} target="_blank" rel="noreferrer" className="hover:underline" data-testid={`stack-child-${child.id}-pr`}>
                        Pull request{child.ciStatus ? ` (${child.ciStatus})` : ''}
                    </a>
                </p>
            )}
            {isQueued && (
                <div className="mt-1.5 flex flex-col items-start gap-2">
                    <p className="text-[12px] text-muted" data-testid={`stack-child-${child.id}-reason`}>{waitingReason(waiting.awake)}</p>
                    {waiting.canRaise && waiting.current < waiting.max && (
                        <Button variant="secondary" onClick={waiting.onRaise} disabled={waiting.isSaving} data-testid={`stack-child-${child.id}-start-now`}>
                            Start now and allow {waiting.current + 1} at a time
                        </Button>
                    )}
                </div>
            )}
            {detail && <p className="mt-1 line-clamp-2 text-[12px] text-muted">{detail}</p>}
            {child.canOverride && !child.takenOver && (
                <div className="mt-2 flex flex-wrap gap-2">
                    <Button variant="secondary" onClick={() => act(takeOver)} data-testid={`stack-child-${child.id}-take-over`}>Take it over</Button>
                    <Button variant="secondary" onClick={() => act(split)}>Split it up</Button>
                    <Button variant="tertiary" onClick={() => act(drop)}>Drop it</Button>
                </div>
            )}
        </li>
    );
}
