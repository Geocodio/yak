import { StatusPill, cn } from '@geocodio/console-ui';
import { STATUS } from '@/lib/status';
import type { TaskDetail } from '@/types/tasks';

function Meta({ label, children, mono }: { label: string; children: React.ReactNode; mono?: boolean }) {
    return (
        <span className="flex items-center gap-1 text-[12px]">
            <span className="text-faint">{label}</span>
            <span className={cn('text-muted', mono && 'font-mono text-[11px]')}>{children}</span>
        </span>
    );
}

/**
 * Status, headline, and run metadata for a task. It scrolls with the thread
 * in the left column so the sidebar can run the full height of the page.
 */
export function TaskSummary({ task }: { task: TaskDetail }) {
    return (
        <div data-testid="task-summary">
            <div className="flex items-center gap-2">
                <StatusPill tone={STATUS[task.status].tone} label={task.statusLabel} pulse={STATUS[task.status].live} />
                <span className="tnum text-[12px] text-faint">#{task.id}</span>
                {task.attemptCount > 1 && (
                    <>
                        <span className="text-faint">·</span>
                        <span className="text-[12px] text-faint">
                            Attempt {task.attempt} of {task.attemptCount}
                        </span>
                    </>
                )}
            </div>
            <h1 className="mt-2 text-[20px] font-semibold leading-snug tracking-tight">{task.headline}</h1>

            {task.status === 'failed' && task.error && (
                <div className="mt-3 rounded-card border border-fail/30 bg-fail-soft/40 px-4 py-3 text-[13px] text-fail">{task.error}</div>
            )}

            {task.nextSteps && (
                <p className="mt-2 text-[13px] italic text-muted" data-testid="next-steps">
                    {task.nextSteps}
                </p>
            )}

            <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5">
                <Meta label="Mode">{task.mode[0].toUpperCase() + task.mode.slice(1)}</Meta>
                {task.repo && (
                    <Meta label="Repo">
                        {task.repoUrl ? (
                            <a href={task.repoUrl} className="text-accent-text hover:underline">
                                {task.repo}
                            </a>
                        ) : (
                            task.repo
                        )}
                    </Meta>
                )}
                <Meta label="Source">
                    {task.sourceUrl ? (
                        <a href={task.sourceUrl} target="_blank" rel="noopener noreferrer" className="text-accent-text hover:underline" data-testid="source-link">
                            {task.sourceLabel}
                        </a>
                    ) : (
                        task.sourceLabel
                    )}
                </Meta>
                {task.model && <Meta label="Model">{task.model}</Meta>}
                {task.turns !== null && <Meta label="Turns">{task.turns}</Meta>}
                <Meta label="Duration">{task.duration}</Meta>
                {task.cost && <Meta label="Cost">{task.cost}</Meta>}
                {task.branch && (
                    <Meta label="Branch" mono>
                        {task.branch}
                    </Meta>
                )}
            </div>
        </div>
    );
}
