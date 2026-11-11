import { Button } from '@geocodio/console-ui';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ChildStateDot, CHILD_STATE_LABEL } from '@/components/threads/stack/ChildStateDot';
import { show } from '@/routes/threads';
import { drop, split, takeOver } from '@/routes/threads/stack';
import type { ConversationItem } from './conversationItem';

/**
 * One sub-thread in the parent's conversation. The brief collapses once the child has started.
 * A failed child offers the person three ways out; each needs the right to drive the stack.
 */
export function ChildCard({ item, canDrive }: { item: ConversationItem; canDrive: boolean }) {
    const child = item.child;
    const [hasActed, setHasActed] = useState(false);

    if (!child) {
        return null;
    }

    const act = (route: typeof takeOver) => {
        setHasActed(true);
        router.post(route.url(child.childThreadId), {}, { preserveScroll: true, onError: () => setHasActed(false) });
    };

    return (
        <div className="ml-9 rounded-card border border-hair bg-panel px-3 py-2.5 text-[13px]" data-testid={`child-card-${child.childThreadId}`} data-state={child.state}>
            <div className="flex flex-wrap items-center gap-2">
                <ChildStateDot state={child.state} />
                <span className="font-medium text-body">{child.name}</span>
                <span className="rounded-pill border border-hair bg-panel-2 px-2 py-0.5 text-[11px] text-muted" data-testid="child-card-kind">{child.kind}</span>
                {child.model && <span className="text-[12px] text-muted">{child.model}</span>}
                <span className="ml-auto text-[12px] text-muted">{CHILD_STATE_LABEL[child.state]}</span>
            </div>

            {child.state === 'working' && child.step && <p className="mt-1 text-[12px] text-muted" data-testid="child-card-step">{child.step}</p>}
            {child.messagesPaused && <p className="mt-1 text-[12px] text-muted" data-testid="child-card-paused">Messages paused for a minute</p>}
            {child.state === 'done' && child.resultSummary && <p className="mt-2 line-clamp-4 whitespace-pre-wrap text-[12.5px] text-body" data-testid="child-card-result">{child.resultSummary}</p>}
            {child.branch && <p className="mt-1 truncate font-mono text-[11px] text-muted">{child.branch}</p>}

            <div className="mt-2 flex flex-wrap items-center gap-2">
                <Link href={show.url(child.childThreadId)} className="text-[12px] text-accent-text hover:underline" data-testid="child-card-open">Open</Link>
                {child.state === 'failed' && canDrive && !hasActed && (
                    <>
                        <Button variant="secondary" onClick={() => act(takeOver)} data-testid="child-take-over">Take it over</Button>
                        <Button variant="secondary" onClick={() => act(split)} data-testid="child-split">Split it up</Button>
                        <Button variant="tertiary" onClick={() => act(drop)} data-testid="child-drop">Drop it</Button>
                    </>
                )}
            </div>
        </div>
    );
}
