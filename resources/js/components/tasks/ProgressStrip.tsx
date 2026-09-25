import { cn } from '@geocodio/console-ui';
import type { ProgressStep } from '@/types/tasks';

/** Seven thin segments and one line of text: the phone-sized version of the progress checklist. */
export function ProgressStrip({ steps }: { steps: ProgressStep[] }) {
    const currentIndex = steps.findIndex((step) => step.current);
    const current = currentIndex >= 0 ? steps[currentIndex] : null;
    const next = currentIndex >= 0 ? steps[currentIndex + 1] : null;

    return (
        <div data-testid="progress-strip">
            <div className="flex gap-1">
                {steps.map((step) => (
                    <span
                        key={step.label}
                        title={step.tooltip}
                        className={cn('h-1 flex-1 rounded-full', step.done && !step.current ? 'bg-ok' : step.current ? 'bg-info' : 'bg-panel-2')}
                    />
                ))}
            </div>
            <div className="mt-1.5 flex justify-between text-[12px] text-muted">
                <span>{current ? `${current.label} · step ${currentIndex + 1} of ${steps.length}` : `${steps.filter((step) => step.done).length} of ${steps.length} done`}</span>
                {next && <span className="text-faint">{next.label} next</span>}
            </div>
        </div>
    );
}
