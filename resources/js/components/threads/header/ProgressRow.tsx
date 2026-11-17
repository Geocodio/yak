import { cn } from '@geocodio/console-ui';
import type { ThreadProgress } from '@/types/threads';

const BAR: Record<ThreadProgress['steps'][number]['state'], string> = {
    done: 'bg-ok',
    current: 'bg-accent',
    failed: 'bg-fail',
    upcoming: 'bg-hair',
};

/** The staged row under the header: Received through Pull request. It scrolls sideways when the screen is narrow. */
export function ProgressRow({ progress }: { progress: ThreadProgress }) {
    return (
        <ol aria-label="Progress" className="m-0 flex shrink-0 list-none items-center gap-4 overflow-x-auto border-b border-hair px-4 py-2 text-[12.5px] whitespace-nowrap text-muted sm:px-5" data-testid="thread-progress-row">
            {progress.steps.map((step) => (
                <li
                    key={step.key}
                    title={step.tooltip}
                    aria-current={step.state === 'current' ? 'step' : undefined}
                    data-state={step.state}
                    className={cn('flex shrink-0 items-center gap-1.5', step.state === 'current' && 'font-medium text-body', step.state === 'failed' && 'font-semibold text-fail')}
                >
                    <span className={cn('h-[3px] w-[34px] rounded-pill', BAR[step.state])} aria-hidden />
                    {step.label}
                </li>
            ))}
        </ol>
    );
}
