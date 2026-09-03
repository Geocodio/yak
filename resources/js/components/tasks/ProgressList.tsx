import { Tooltip, cn } from '@geocodio/console-ui';
import { Check } from 'lucide-react';
import type { ProgressStep } from '@/types/tasks';

export function ProgressList({ steps }: { steps: ProgressStep[] }) {
    const doneCount = steps.filter((step) => step.done).length;

    return (
        <section data-testid="progress-checklist">
            <div className="mb-2 flex items-center justify-between">
                <h2 className="text-[11px] font-semibold uppercase tracking-wide text-faint">Progress</h2>
                <span className="tnum text-[11px] text-faint">
                    {doneCount} / {steps.length}
                </span>
            </div>
            <ol className="flex flex-col gap-1">
                {steps.map((step, index) => (
                    <Tooltip key={step.label} label={step.tooltip}>
                        <li
                            className={cn(
                                'flex items-center gap-2 text-[12px]',
                                step.done && !step.current ? 'text-muted' : step.current ? 'text-body' : 'text-faint',
                            )}
                            data-testid={`progress-step-${index}`}
                        >
                            <span
                                className={cn(
                                    'flex h-4 w-4 items-center justify-center rounded-pill border',
                                    step.done && !step.current ? 'border-ok bg-ok text-accent-ink' : step.current ? 'border-info' : 'border-hair-strong',
                                )}
                            >
                                {step.done && !step.current && <Check size={10} strokeWidth={3} />}
                                {step.current && <span className="h-1.5 w-1.5 animate-pulse rounded-pill bg-info" />}
                            </span>
                            {step.label}
                        </li>
                    </Tooltip>
                ))}
            </ol>
        </section>
    );
}
