import { cn } from '@geocodio/console-ui';
import { ChevronRight, RotateCcw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { formatStepDuration } from '@/components/tasks/groupActivity';
import { Elapsed } from './Elapsed';
import { useOpenKey } from './openKeys';
import { useStepDetailSource } from './StepDetailContext';
import { StepDetail, stepPrefix } from './StepDetail';
import { StepTile } from './stepIcons';
import type { StepView } from './types';

export function FailedBadge({ children }: { children: React.ReactNode }) {
    return <span className="inline-flex flex-none items-center gap-1 rounded-full bg-fail-soft px-2 py-0.5 text-[11.5px] font-semibold whitespace-nowrap text-fail">{children}</span>;
}

/**
 * One step: its icon tile, the command or path on one line, and on the right
 * its duration, an exit badge, or the "retry passed" mark. It opens in place
 * to the full command, its output and a footer. `current` is the step that is
 * running now: the one tinted row, with a spinning tile, a sheen across the
 * command and a clock that ticks.
 */
export function StepRow({ step, current = false, fresh = false }: { step: StepView; current?: boolean; fresh?: boolean }) {
    const source = useStepDetailSource();
    const { defaultOpen, remember } = useOpenKey(step.key);
    const [isOpen, setIsOpen] = useState(defaultOpen);
    const isThinking = step.category === 'thinking';
    const prefix = stepPrefix(step);
    const duration = formatStepDuration(step.durationMs);

    useEffect(() => {
        if (isOpen && step.logId !== null && step.content === undefined && !step.running) {
            source?.request(step.logId);
        }
        // The request is keyed on the row finishing too, so a step opened while running fetches its output once it has one.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, step.logId, step.running]);

    return (
        <details
            className={cn('activity-details min-w-0', fresh && 'activity-rise')}
            open={defaultOpen}
            onToggle={(event) => {
                remember(event.currentTarget.open);
                setIsOpen(event.currentTarget.open);
            }}
            data-testid={current ? 'step-current' : 'step-row'}
            data-failed={step.failed || undefined}
            data-log-id={step.logId ?? undefined}
        >
            <summary className={cn('relative flex min-w-0 cursor-pointer items-center gap-[9px] rounded-lg py-1 pr-1.5 pl-[3px] hover:bg-panel-2', current && 'bg-accent-soft hover:bg-accent-soft')}>
                <StepTile category={step.category} failed={step.failed} current={current} />
                <span className={cn('min-w-0 flex-1 overflow-hidden text-[13px] text-ellipsis whitespace-nowrap text-body', isThinking ? 'text-muted' : 'font-mono text-[12px]')}>
                    {prefix && <span className={cn('text-faint select-none', prefix !== '$' && 'font-sans text-[12px]')}>{prefix} </span>}
                    <span className={cn(current && 'activity-sheen')}>{step.label}</span>
                </span>
                <span className="inline-flex flex-none items-center gap-2">
                    {current ? (
                        <Elapsed since={step.createdAt} className="tnum text-[12px] font-semibold text-accent-text" />
                    ) : step.failed ? (
                        <FailedBadge>{step.exitCode !== null ? `exit ${step.exitCode}` : 'failed'}</FailedBadge>
                    ) : (
                        <>
                            {step.retryPassed && (
                                <span className="inline-flex items-center gap-1 text-[11.5px] font-medium whitespace-nowrap text-ok">
                                    <RotateCcw size={11} aria-hidden />
                                    retry passed
                                </span>
                            )}
                            {duration && <span className="tnum text-[12px] whitespace-nowrap text-faint">{duration}</span>}
                        </>
                    )}
                </span>
                <ChevronRight size={14} className="activity-chevron shrink-0 text-faint transition-transform duration-150" aria-hidden />
            </summary>
            {isOpen && <StepDetail step={step} />}
        </details>
    );
}
