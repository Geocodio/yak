import { Elapsed } from './Elapsed';
import { StepRow } from './StepRow';
import { StepTile } from './stepIcons';
import type { StepView } from './types';

/** The running step: the stream's one tinted row. */
export function CurrentStep({ step, fresh }: { step: StepView; fresh?: boolean }) {
    return <StepRow step={step} current fresh={fresh} />;
}

/** Shown while the run is live but no tool call is open: the agent is between steps. */
export function PendingStep({ since }: { since: string | null }) {
    return (
        <div className="flex min-w-0 items-center gap-[9px] rounded-lg bg-accent-soft py-1 pr-2.5 pl-[3px]" data-testid="step-pending">
            <StepTile category="thinking" current />
            <span className="min-w-0 flex-1 text-[13px] text-muted">Working…</span>
            <Elapsed since={since} className="tnum text-[12px] font-semibold text-accent-text" />
        </div>
    );
}
