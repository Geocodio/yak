import { cn } from '@geocodio/console-ui';
import type { ChildCardState } from '@/types/thread-events';
import { CHILD_STATE_LABEL, CHILD_STATE_PILL } from './ChildStateDot';

/** A tinted state pill. `label` overrides the default wording, e.g. "Asked you" for a waiting child. */
export function StatePill({ state, label }: { state: ChildCardState; label?: string }) {
    return (
        <span className={cn('inline-flex shrink-0 items-center rounded-pill px-2 py-0.5 text-[11.5px] font-medium', CHILD_STATE_PILL[state])} data-testid="state-pill" data-state={state}>
            {label ?? CHILD_STATE_LABEL[state]}
        </span>
    );
}
