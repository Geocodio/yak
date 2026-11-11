import { Spinner, cn } from '@geocodio/console-ui';
import { AlertTriangle } from 'lucide-react';
import { newMethod } from '@/routes/threads';
import type { ThreadNotice } from '@/types/threads';
import { RestoreProgress, RetryRestoreButton } from './RestoreProgress';

export function RebuildCard({ notice, threadId, repo, branch, readonly }: { notice: ThreadNotice; threadId: number; repo: string; branch: string | null; readonly: boolean }) {
    const steps = notice.restoreSteps;
    const failedIndex = steps.findIndex((step) => step.status === 'failed');
    const isFailed = notice.kind === 'restore_failed';
    const failed = failedIndex === -1 ? null : steps[failedIndex];
    const doneCount = steps.filter((step) => step.status === 'done').length;
    const current = isFailed && failedIndex !== -1 ? failedIndex + 1 : Math.min(doneCount + 1, Math.max(steps.length, 1));
    const scriptUrl = branch === null ? `https://github.com/${repo}/blob/HEAD/.yak/setup.sh` : `https://github.com/${repo}/blob/${branch}/.yak/setup.sh`;

    return (
        <div className={cn('rounded-card p-4', isFailed ? 'bg-fail-soft' : 'bg-info-soft')} data-testid="thread-notice" data-kind={notice.kind}>
            <div className="flex items-start gap-3">
                {isFailed ? <AlertTriangle className="mt-0.5 size-4 shrink-0 text-fail" aria-hidden /> : <Spinner size="sm" className="mt-0.5" />}
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium text-body">{notice.title}</p>
                    <p className="mt-1 text-[13px] text-muted">{notice.body}</p>
                    {steps.length > 0 && (
                        <>
                            <div className="mt-3 flex items-center gap-3">
                                <div className="h-1.5 flex-1 overflow-hidden rounded-pill bg-panel-2" role="progressbar" aria-valuemin={0} aria-valuemax={steps.length} aria-valuenow={doneCount}>
                                    <div className={cn('h-full rounded-pill', isFailed ? 'bg-fail' : 'bg-brand')} style={{ width: `${(doneCount / steps.length) * 100}%` }} />
                                </div>
                                <span className="shrink-0 text-[12px] text-muted" data-testid="rebuild-step-count">
                                    Step {current} of {steps.length}
                                </span>
                            </div>
                            <RestoreProgress steps={steps} />
                        </>
                    )}
                    {failed !== null && (
                        <div className="mt-3 rounded-card bg-app p-3" data-testid="rebuild-failed-panel">
                            <p className="text-[13px] font-medium text-fail">
                                {failed.key === 'run_setup_script' ? '.yak/setup.sh stopped' : `${failed.label} stopped`}
                                {failed.exitCode !== null && ` with exit code ${failed.exitCode}`}
                            </p>
                            {failed.outputTail.length > 0 && (
                                <pre className="mt-2 whitespace-pre-wrap break-words font-mono text-[12px] text-muted" data-testid="rebuild-output-tail">
                                    {failed.outputTail.join('\n')}
                                </pre>
                            )}
                            {failed.outputTail.length === 0 && failed.error && <p className="mt-2 break-words text-[12px] text-muted">{failed.error}</p>}
                            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-[13px]">
                                {!readonly && <RetryRestoreButton threadId={threadId} testId="restore-retry" />}
                                <a href={scriptUrl} target="_blank" rel="noreferrer" className="text-accent-text hover:underline">
                                    Open the script on GitHub
                                </a>
                                <a href="https://yak.build/docs/threads" target="_blank" rel="noreferrer" className="text-accent-text hover:underline">
                                    About .yak/setup.sh
                                </a>
                                <a href={newMethod.url({ query: branch === null ? { repo } : { repo, branch } })} className="text-accent-text hover:underline">
                                    Start a new thread from this branch
                                </a>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
