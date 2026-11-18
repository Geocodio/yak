import { Button } from '@geocodio/console-ui';
import { Loader2, RotateCcw, TriangleAlert } from 'lucide-react';
import { useRouterAction } from '@/lib/useRouterAction';
import { setupAndContinue } from '@/routes/threads';
import type { ThreadNotice } from '@/types/threads';

/** The conversation card for a thread whose repository is still being set up, or whose setup failed. */
export function RepositorySetupCard({ notice, threadId }: { notice: ThreadNotice; threadId: string }) {
    const isFailed = notice.kind === 'repository_setup_failed';
    const action = useRouterAction();

    return (
        <div className={isFailed ? 'rounded-card bg-fail-soft p-4' : 'rounded-card bg-info-soft p-4'} data-testid="thread-notice" data-kind={notice.kind}>
            <div className="flex items-start gap-3">
                {isFailed ? <TriangleAlert className="mt-0.5 size-4 shrink-0 text-fail" aria-hidden /> : <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-info motion-reduce:animate-none" aria-hidden />}
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium text-body">{notice.title}</p>
                    <p className="mt-1 text-[13px] text-muted">{notice.body}</p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {isFailed && notice.repositoryUrl != null && (
                            <a href={notice.repositoryUrl} data-testid="thread-open-repository" className="inline-block rounded-card bg-fail px-3 py-1.5 text-[13px] font-medium text-white">
                                Open repository
                            </a>
                        )}
                        {notice.failedSetupRunUrl != null && (
                            <a href={notice.failedSetupRunUrl} data-testid="thread-failed-setup-run" className="inline-block rounded-card border border-hair bg-panel px-3 py-1.5 text-[13px] font-medium text-body hover:bg-panel-2">
                                Show the failed setup run
                            </a>
                        )}
                        {notice.canContinueAfterSetup === true && (
                            <Button
                                variant="primary"
                                icon={<RotateCcw size={14} />}
                                pending={action.isPending('setup-and-continue')}
                                pendingLabel="Queueing…"
                                disabled={action.busy}
                                onClick={() => action.run('setup-and-continue', 'post', setupAndContinue.url(threadId))}
                                data-testid="thread-setup-and-continue"
                            >
                                Continue when setup finishes
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
