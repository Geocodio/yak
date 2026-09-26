import { Link } from '@inertiajs/react';
import { Badge } from '@geocodio/console-ui';
import { GitPullRequest, Globe, Terminal } from 'lucide-react';
import { StatusDot } from '@/components/StatusDot';
import { PR_TONE, SOURCE_ICON } from '@/components/tasks/TaskTable';
import { show as showTask } from '@/routes/tasks';
import type { TaskRow } from '@/types/tasks';

/**
 * One card per task. The first line identifies it (status, id, source,
 * author, age), the description takes two lines, and the last line holds
 * the repo, PR, cost, preview and follow-up count. The description is a
 * stretched link (its `::after` covers the card) so the whole card is
 * clickable without nesting an anchor inside an anchor; the PR badge, the
 * preview globe, and the age tooltip sit above it (`relative z-10`) to stay
 * their own links or keep receiving pointer hover.
 */
function TaskCard({ task }: { task: TaskRow }) {
    const SourceIcon = SOURCE_ICON[task.source] ?? Terminal;
    const identity = [task.sourceLabel, task.by].filter((part): part is string => part !== null && part !== '');

    return (
        <article
            data-testid={`task-row-${task.id}`}
            className="relative block rounded-card border border-hair bg-panel px-3.5 py-3 shadow-card active:bg-panel-2"
        >
            <div className="flex items-center gap-2 text-[12px] text-faint">
                <StatusDot status={task.status} />
                <span className="font-mono">{task.externalId ? task.externalId : `#${task.id}`}</span>
                <span className="flex min-w-0 items-center gap-1">
                    <SourceIcon size={12} className="shrink-0" />
                    <span className="truncate">{identity.join(' · ')}</span>
                </span>
                <span className="relative z-10 tnum ml-auto shrink-0" title={task.createdTooltip}>
                    {task.createdAgo}
                </span>
            </div>

            <Link
                href={showTask.url(task.id)}
                data-testid="task-card-description"
                className="mt-1.5 block text-[15px] leading-snug text-body after:absolute after:inset-0 after:content-['']"
            >
                <span className="line-clamp-2 block">{task.description}</span>
            </Link>

            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[12px] text-muted">
                {task.repo && (
                    <span data-testid="task-card-repo" className="font-mono text-[11.5px]">
                        {task.repo}
                    </span>
                )}
                {task.pr && (
                    <a
                        href={task.pr.url ?? undefined}
                        target="_blank"
                        rel="noopener noreferrer"
                        data-testid="task-card-pr"
                        className="relative z-10 inline-flex"
                    >
                        <Badge tone={PR_TONE[task.pr.state]}>
                            <GitPullRequest size={11} className="mr-1 inline" />
                            {task.pr.number ? `#${task.pr.number}` : task.pr.state}
                        </Badge>
                    </a>
                )}
                {task.deploymentUrl && (
                    <a
                        href={task.deploymentUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={`Open the branch preview for task ${task.externalId ?? task.id}`}
                        className="relative z-10 text-faint"
                    >
                        <Globe size={14} />
                    </a>
                )}
                {task.cost && <span className="tnum">{task.cost}</span>}
                {task.followUps.length > 0 && (
                    <Badge tone="neutral">
                        {task.followUps.length} {task.followUps.length === 1 ? 'follow-up' : 'follow-ups'}
                    </Badge>
                )}
            </div>
        </article>
    );
}

export function TaskCardList({ tasks }: { tasks: TaskRow[] }) {
    return (
        <div data-testid="task-cards" className="flex flex-col gap-2.5 px-4 py-3">
            {tasks.map((task) => (
                <TaskCard key={task.id} task={task} />
            ))}
        </div>
    );
}
