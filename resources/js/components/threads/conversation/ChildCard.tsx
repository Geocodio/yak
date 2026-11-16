import { Button } from '@geocodio/console-ui';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { KindIcon } from '@/components/threads/stack/KindIcon';
import { StatePill } from '@/components/threads/stack/StatePill';
import { show } from '@/routes/threads';
import { drop, split, takeOver } from '@/routes/threads/stack';
import type { ConversationItem } from './conversationItem';
import { MergeDecisionFold } from './MergeDecisionFold';

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

    const asksYou = child.state === 'waiting' && child.question !== null;
    const subLine = asksYou
        ? 'Waiting for your answer'
        : child.mergedAt
          ? `Merged into this branch at ${new Date(child.mergedAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}`
          : null;

    return (
        <div className="ml-9 rounded-card border border-hair bg-panel px-3 py-2.5 text-[13px]" data-testid={`child-card-${child.childThreadId}`} data-state={child.state}>
            <div className="flex flex-wrap items-center gap-2">
                <KindIcon kind={child.kind} />
                <span className="font-medium text-body">{child.name}</span>
                <span className="rounded-pill border border-hair bg-panel-2 px-2 py-0.5 text-[11px] text-muted" data-testid="child-card-kind">{child.kind}</span>
                {child.model && <span className="rounded-pill border border-hair bg-panel-2 px-2 py-0.5 text-[11px] text-muted">{child.model}</span>}
                <span className="ml-auto"><StatePill state={child.state} label={asksYou ? 'Asked you' : undefined} /></span>
            </div>

            {subLine && <p className="mt-1 text-[12px] text-muted" data-testid="child-card-subline">{subLine}</p>}
            {child.question && <p className="mt-2 rounded-chip bg-warn-soft px-2.5 py-1.5 text-[12.5px] text-body" data-testid="child-card-question">Asks you: {child.question}</p>}
            {child.state === 'queued' && <p className="mt-1 text-[12px] text-muted" data-testid="child-card-queued">Starts when another sub-thread finishes</p>}
            {child.mergedAt && <MergeDecisionFold decision={child.decision} conflictResolved={child.conflictResolved} />}

            {child.state === 'working' && child.step && <p className="mt-1 text-[12px] text-muted" data-testid="child-card-step">{child.step}</p>}
            {child.messagesPaused && <p className="mt-1 text-[12px] text-muted" data-testid="child-card-paused">Messages paused for a minute</p>}
            {child.state === 'done' && child.resultSummary && <p className="mt-2 line-clamp-4 whitespace-pre-wrap text-[12.5px] text-body" data-testid="child-card-result">{child.resultSummary}</p>}
            {child.branch && <p className="mt-1 truncate font-mono text-[11px] text-muted">{child.branch}</p>}

            <div className="mt-2 flex flex-wrap items-center gap-2">
                {child.filesChanged !== null && (
                    <span className="text-[12px] text-muted" data-testid="child-card-totals">
                        {child.filesChanged} {child.filesChanged === 1 ? 'file' : 'files'} <span className="text-ok">+{child.additions ?? 0}</span> <span className="text-fail">-{child.deletions ?? 0}</span>
                    </span>
                )}
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
