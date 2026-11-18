import { cn } from '@geocodio/console-ui';
import { useLayoutEffect, useRef } from 'react';
import { isStepNearFocus, progressFocusIndex } from '@/threads/phoneHeader';
import type { ThreadProgress } from '@/types/threads';

const STATE_TEXT: Record<ThreadProgress['steps'][number]['state'], string> = { done: 'done', current: 'in progress', failed: 'failed', upcoming: 'not yet' };

const BAR: Record<ThreadProgress['steps'][number]['state'], string> = {
    done: 'bg-ok',
    current: 'bg-accent',
    failed: 'bg-fail',
    upcoming: 'bg-hair',
};

const isWaiting = (step: ThreadProgress['steps'][number]): boolean => step.key === 'waiting_for_repository' && step.state === 'current';

/** The staged row under the header: Received through Pull request. It scrolls sideways when the screen is narrow. */
export function ProgressRow({ progress, isShownOnPhone = true }: { progress: ThreadProgress; isShownOnPhone?: boolean }) {
    const listRef = useRef<HTMLOListElement>(null);
    const focusState = ['failed', 'current'].find((state) => progress.steps.some((step) => step.state === state)) ?? 'done';
    const focusKey = [...progress.steps].reverse().find((step) => step.state === focusState)?.key;

    // Scrolls the strip itself (never the page) so the focused step's right edge is visible; no animation.
    useLayoutEffect(() => {
        const list = listRef.current;
        const target = list?.querySelector<HTMLElement>('[data-focus="true"]');
        if (list && target) {
            list.scrollLeft = Math.max(0, target.offsetLeft + target.offsetWidth + 16 - list.clientWidth);
        }
    }, [focusKey]);

    const focusIndex = progressFocusIndex(progress.steps.map((step) => step.state));

    return (
        <ol ref={listRef} aria-label="Progress" className={cn('relative m-0 flex shrink-0 list-none items-center gap-4 overflow-x-auto border-b border-hair px-4 py-2 max-sm:gap-1.5 max-sm:px-3.5 max-sm:py-2 text-[12.5px] whitespace-nowrap text-muted sm:px-5', !isShownOnPhone && 'max-sm:hidden')} data-testid="thread-progress-row">
            {progress.steps.map((step, index) => (
                <li
                    key={step.key}
                    title={step.tooltip}
                    aria-current={step.state === 'current' ? 'step' : undefined}
                    data-state={step.state}
                    data-focus={step.key === focusKey}
                    className={cn('flex shrink-0 items-center gap-1.5', !isStepNearFocus(index, focusIndex) && 'max-sm:hidden', step.state === 'current' && 'font-medium text-body', step.state === 'failed' && 'font-semibold text-fail', isWaiting(step) && 'font-semibold text-warn')}
                >
                    <span className={cn('h-[3px] w-[34px] rounded-pill', isWaiting(step) ? 'bg-warn' : BAR[step.state])} aria-hidden />
                    {step.label}
                    <span className="sr-only"> ({STATE_TEXT[step.state]})</span>
                </li>
            ))}
        </ol>
    );
}
