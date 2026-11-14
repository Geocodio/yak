import { cn } from '@geocodio/console-ui';
import { Elapsed } from './Elapsed';
import { StepRow } from './StepRow';
import { StepTile } from './stepIcons';
import type { StepView } from './types';

/** The running step: the stream's one tinted row. */
export function CurrentStep({ step, fresh }: { step: StepView; fresh?: boolean }) {
    return <StepRow step={step} current fresh={fresh} />;
}

/** Shown while the run is live but no tool call is open: the agent is between steps. `quiet` is for work that has not started yet. */
export function PendingStep({ since, label = 'Working…', quiet = false, testId = 'step-pending' }: { since: string | null; label?: string; quiet?: boolean; testId?: string }) {
    return (
        <div className={cn('flex min-w-0 items-center gap-[9px] rounded-lg py-1 pr-2.5 pl-[3px]', !quiet && 'bg-accent-soft')} data-testid={testId}>
            <StepTile category="thinking" current />
            <span className="min-w-0 flex-1 text-[13px] text-muted">{label}</span>
            <Elapsed since={since} className={cn('tnum text-[12px]', quiet ? 'text-faint' : 'font-semibold text-accent-text')} />
        </div>
    );
}
