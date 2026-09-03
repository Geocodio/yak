import { router } from '@inertiajs/react';
import { Button, ConfirmDialog, Menu, StatusPill, cn, toast } from '@geocodio/console-ui';
import { ChevronRight, ExternalLink, FileText, Globe, GitPullRequest, ListTree, Menu as MenuIcon, Wrench } from 'lucide-react';
import { useState } from 'react';
import { cancel, rerunReview, reroute, retry, show } from '@/routes/tasks';
import { tasks as tasksIndex } from '@/routes';
import { STATUS } from '@/lib/status';
import type { ActionsData, DeploymentData, TaskDetail } from '@/types/tasks';

type ConfirmAction = { kind: 'retry' | 'cancel' | 'rerunReview' | 'reroute'; target?: string };

function Meta({ label, children, mono }: { label: string; children: React.ReactNode; mono?: boolean }) {
    return (
        <span className="flex items-center gap-1 text-[12px]">
            <span className="text-faint">{label}</span>
            <span className={cn('text-muted', mono && 'font-mono text-[11px]')}>{children}</span>
        </span>
    );
}

export function HeaderBand({
    task,
    actions,
    deployment,
    onOpenTranscript,
    onOpenDetailsDrawer,
}: {
    task: TaskDetail;
    actions: ActionsData;
    deployment: DeploymentData;
    onOpenTranscript: () => void;
    onOpenDetailsDrawer: () => void;
}) {
    const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
    const [busy, setBusy] = useState(false);

    const runConfirm = () => {
        if (!confirm) {
            return;
        }
        setBusy(true);
        const finish = () => {
            setBusy(false);
            setConfirm(null);
        };
        if (confirm.kind === 'retry') {
            router.post(retry.url(task.id), {}, { preserveScroll: true, onFinish: finish });
        } else if (confirm.kind === 'cancel') {
            router.post(cancel.url(task.id), {}, { preserveScroll: true, onFinish: finish });
        } else if (confirm.kind === 'rerunReview') {
            router.post(rerunReview.url(task.id), {}, { preserveScroll: true, onFinish: finish });
        } else {
            router.post(reroute.url(task.id), { repo: confirm.target }, { preserveScroll: true, onFinish: finish });
        }
    };

    // Mirrors the old `TaskDetail::contextualAction()` priority exactly:
    // review mode always wins (any status), regardless of canRetry/canCancel;
    // `actions.canRerunReview` is already mode-gated server-side
    // (`TaskDetailData::actions()`), so no client-side status check here.
    const showRerunReview = actions.canRerunReview;
    const showRetry = !showRerunReview && actions.canRetry;
    const showCancelButton = !showRerunReview && !showRetry && actions.canCancel;

    const copyLink = () => {
        void navigator.clipboard.writeText(window.location.origin + show.url(task.id));
        toast.success('Task link copied');
    };

    return (
        <>
            <header className="flex h-12 shrink-0 items-center gap-3 border-b border-hair bg-app px-5">
                <div className="flex min-w-0 items-center gap-1.5 text-[13px]">
                    <a href={tasksIndex.url()} className="text-muted hover:text-body">
                        Tasks
                    </a>
                    <ChevronRight size={12} className="text-faint" />
                    <span className="truncate font-medium text-body">{task.externalId ?? `#${task.id}`}</span>
                </div>

                <div className="ml-auto flex items-center gap-2">
                    <Button
                        variant="tertiary"
                        icon={<MenuIcon size={13} />}
                        className="lg:hidden"
                        data-testid="details-drawer-trigger"
                        onClick={onOpenDetailsDrawer}
                    >
                        Details
                    </Button>

                    {showRerunReview && (
                        <Button variant="primary" icon={<Wrench size={13} />} onClick={() => setConfirm({ kind: 'rerunReview' })}>
                            Re-run review
                        </Button>
                    )}
                    {showRetry && (
                        <Button variant="primary" icon={<Wrench size={13} />} onClick={() => setConfirm({ kind: 'retry' })}>
                            Retry
                        </Button>
                    )}
                    {showCancelButton && (
                        <Button variant="tertiary" data-testid="cancel-button" onClick={() => setConfirm({ kind: 'cancel' })}>
                            Cancel
                        </Button>
                    )}

                    {deployment && (
                        <Button
                            variant="secondary"
                            icon={<Globe size={13} />}
                            onClick={() => window.open(deployment.url, '_blank', 'noopener,noreferrer')}
                            data-testid="preview-button"
                        >
                            Preview
                        </Button>
                    )}

                    {task.pr ? (
                        <Button
                            variant="primary"
                            icon={<GitPullRequest size={13} />}
                            onClick={() => window.open(task.pr!.url ?? undefined, '_blank', 'noopener,noreferrer')}
                            data-testid="outcome-button"
                        >
                            PR #{task.pr.number} <ExternalLink size={11} className="ml-1 text-faint" />
                        </Button>
                    ) : task.researchArtifactUrl ? (
                        <Button
                            variant="primary"
                            icon={<FileText size={13} />}
                            onClick={() => window.open(task.researchArtifactUrl!, '_blank', 'noopener,noreferrer')}
                            data-testid="research-report-button"
                        >
                            View report
                        </Button>
                    ) : null}

                    <Menu
                        trigger={<MenuIcon size={15} />}
                        className="h-8 w-8 px-0"
                        align="end"
                        items={[
                            { key: 'transcript', label: 'Open full transcript', icon: <ListTree size={13} />, onSelect: onOpenTranscript },
                            ...actions.rerouteTargets.map((repo) => ({
                                key: `move-${repo}`,
                                label: `Move to ${repo}`,
                                onSelect: () => setConfirm({ kind: 'reroute', target: repo }),
                            })),
                            { key: 'copy', label: 'Copy task link', onSelect: copyLink },
                            ...(actions.canCancel
                                ? [{ key: 'cancel', label: 'Cancel task', danger: true, dividerAbove: true, onSelect: () => setConfirm({ kind: 'cancel' }) }]
                                : []),
                        ]}
                    />
                </div>
            </header>

            <div className="mx-auto w-full max-w-[820px] px-8 pt-6">
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

            <ConfirmDialog
                open={confirm !== null}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={
                    confirm?.kind === 'cancel'
                        ? 'Cancel this task?'
                        : confirm?.kind === 'retry'
                          ? 'Retry this task?'
                          : confirm?.kind === 'rerunReview'
                            ? 'Re-run this review?'
                            : `Move this task to ${confirm?.target ?? ''}?`
                }
                body={
                    confirm?.kind === 'cancel'
                        ? 'The sandbox will be destroyed and the agent will stop.'
                        : confirm?.kind === 'reroute'
                          ? 'The current sandbox (if any) will be destroyed and the task will restart there.'
                          : 'This re-queues the task.'
                }
                confirmLabel={confirm?.kind === 'cancel' ? 'Cancel task' : 'Confirm'}
                destructive={confirm?.kind === 'cancel' || confirm?.kind === 'reroute'}
                busy={busy}
                onConfirm={runConfirm}
                confirmTestId="confirm-action"
            />
        </>
    );
}
