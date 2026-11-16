import { cn } from '@geocodio/console-ui';
import type { ChildCardState } from '@/types/thread-events';

const DOT: Record<ChildCardState, string> = {
    queued: 'border border-brand',
    working: 'bg-brand',
    waiting: 'bg-warn',
    done: 'bg-ok',
    failed: 'bg-fail',
    closed: 'border border-idle',
};

export const CHILD_STATE_LABEL: Record<ChildCardState, string> = {
    queued: 'Waiting for a slot',
    working: 'Working',
    waiting: 'Waiting',
    done: 'Finished',
    failed: 'Failed',
    closed: 'Closed',
};

/** A status dot, never a stripe: filled for live states, hollow for ones that are not running. */
export function ChildStateDot({ state }: { state: ChildCardState }) {
    return <span aria-hidden className={cn('inline-block h-2 w-2 shrink-0 rounded-pill', DOT[state])} data-state={state} />;
}

/** Tinted whole-pill surfaces, one per state. */
export const CHILD_STATE_PILL: Record<ChildCardState, string> = {
    queued: 'border border-hair text-muted',
    working: 'bg-brand-soft text-brand',
    waiting: 'bg-warn-soft text-body',
    done: 'bg-ok-soft text-body',
    failed: 'bg-fail-soft text-fail',
    closed: 'bg-panel-2 text-muted',
};
