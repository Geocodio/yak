import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { describeCounts } from '@/components/tasks/groupActivity';
import type { ActivityCounts } from '@/types/tasks';
import { CurrentStep, PendingStep } from './CurrentStep';
import { useOpenKey } from './openKeys';
import { FailedBadge, StepRow } from './StepRow';
import { StepStack } from './stepIcons';
import type { StepCategory, StepView } from './types';

/** How many steps are earlier than the ones loaded, and how to load them. */
export type EarlierSteps = { count: number; loading: boolean; onLoad: () => void };

export function countsOf(steps: StepView[]): ActivityCounts {
    const counts: ActivityCounts = { command: 0, read: 0, edit: 0, search: 0, fetch: 0, thinking: 0 };
    steps.forEach((step) => {
        counts[step.category] += 1;
    });

    return counts;
}

function failedLabel(steps: StepView[]): string | null {
    const failed = steps.filter((step) => step.failed);

    if (failed.length === 0) {
        return null;
    }

    return `${failed.length} failed${failed.every((step) => step.retried) ? ', retried' : ''}`;
}

/** The rows of a group, joined by the hairline that runs down behind the tiles. */
export function StepList({ children, className = '' }: { children: ReactNode; className?: string }) {
    return (
        <div className={`relative flex flex-col gap-px pl-0.5 before:absolute before:top-3.5 before:bottom-3.5 before:left-[14.5px] before:w-px before:bg-hair before:content-[''] ${className}`}>
            {children}
        </div>
    );
}

export function LoadEarlier({ earlier }: { earlier: EarlierSteps }) {
    return (
        <button
            type="button"
            onClick={earlier.onLoad}
            disabled={earlier.loading}
            className="mt-0.5 mb-1 ml-9 cursor-pointer self-start py-[3px] text-[12px] font-medium text-accent-text hover:underline disabled:opacity-60"
            data-testid="load-earlier"
        >
            {earlier.loading ? 'Loading earlier steps…' : `Load ${earlier.count.toLocaleString()} earlier ${earlier.count === 1 ? 'step' : 'steps'}`}
        </button>
    );
}

/** A group of steps folded to one line: tool icons, what it did, how long it took. Opens to its rows. */
export function StepGroup({
    groupKey,
    steps,
    duration = null,
    earlier,
    freshKeys,
    label,
}: {
    groupKey: string;
    steps: StepView[];
    duration?: string | null;
    earlier?: EarlierSteps;
    freshKeys?: Set<string>;
    /** Replaces the counted description, for a group that is a fold of earlier steps. */
    label?: string;
}) {
    // Keyed by its first and last step as well, so loading earlier steps (which changes the first) keeps an open group open.
    const { defaultOpen, remember } = useOpenKey(groupKey, ...(steps.length > 0 ? [`group:${steps[0].key}`, `group:${steps[steps.length - 1].key}`] : []));
    const categories = new Set<StepCategory>(steps.map((step) => step.category));
    const failed = failedLabel(steps);

    // One step needs no fold: its row is the whole group.
    if (steps.length === 1 && !earlier) {
        return (
            <StepList>
                <StepRow step={steps[0]} fresh={freshKeys?.has(steps[0].key)} />
            </StepList>
        );
    }

    return (
        <details className="activity-details min-w-0" open={defaultOpen} onToggle={(event) => remember(event.currentTarget.open)} data-testid="step-group">
            <summary className="flex min-w-0 cursor-pointer items-center gap-2 rounded-lg px-1.5 py-1.5 text-[13px] text-muted hover:bg-panel-2">
                <ChevronRight size={14} className="activity-chevron shrink-0 text-faint transition-transform duration-150" aria-hidden />
                <StepStack categories={categories} />
                <span className="min-w-0 flex-1">{label ?? describeCounts(countsOf(steps))}</span>
                {failed && <FailedBadge>{failed}</FailedBadge>}
                {duration && <span className="tnum flex-none text-[12px] whitespace-nowrap text-faint">{duration}</span>}
            </summary>
            <StepList className="mt-0.5 mr-0 mb-1 ml-[18px]">
                {earlier && <LoadEarlier earlier={earlier} />}
                {steps.map((step) => (
                    <StepRow key={step.key} step={step} fresh={freshKeys?.has(step.key)} />
                ))}
            </StepList>
        </details>
    );
}

const FINISHED_STEPS_SHOWN = 3;

/**
 * The group that is still running: the last three finished steps and the
 * current one stay on screen, and everything before them is one fold.
 * `currentSince` times the placeholder shown when no tool call is open.
 */
export function ActiveGroup({
    groupKey,
    steps,
    earlier,
    freshKeys,
    currentSince,
}: {
    groupKey: string;
    steps: StepView[];
    earlier?: EarlierSteps;
    freshKeys?: Set<string>;
    currentSince: string | null;
}) {
    const running = steps[steps.length - 1]?.running ? steps[steps.length - 1] : null;
    const finished = running ? steps.slice(0, -1) : steps;
    const shown = finished.slice(-FINISHED_STEPS_SHOWN);
    const folded = finished.slice(0, finished.length - shown.length);
    const foldedCount = folded.length + (earlier?.count ?? 0);

    return (
        <div className="flex flex-col gap-0.5">
            {foldedCount > 0 && (
                <StepGroup groupKey={`${groupKey}:earlier`} steps={folded} earlier={earlier} freshKeys={freshKeys} label={`${foldedCount.toLocaleString()} earlier ${foldedCount === 1 ? 'step' : 'steps'}`} />
            )}
            <StepList>
                {shown.map((step) => (
                    <StepRow key={step.key} step={step} fresh={freshKeys?.has(step.key)} />
                ))}
                {running ? <CurrentStep step={running} fresh={freshKeys?.has(running.key)} /> : <PendingStep since={currentSince} />}
            </StepList>
        </div>
    );
}
