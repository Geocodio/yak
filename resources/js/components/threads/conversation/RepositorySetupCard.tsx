import { Loader2, TriangleAlert } from 'lucide-react';
import type { ThreadNotice } from '@/types/threads';

/** The conversation card for a thread whose repository is still being set up, or whose setup failed. */
export function RepositorySetupCard({ notice }: { notice: ThreadNotice }) {
    const isFailed = notice.kind === 'repository_setup_failed';

    return (
        <div className={isFailed ? 'rounded-card bg-fail-soft p-4' : 'rounded-card bg-info-soft p-4'} data-testid="thread-notice" data-kind={notice.kind}>
            <div className="flex items-start gap-3">
                {isFailed ? <TriangleAlert className="mt-0.5 size-4 shrink-0 text-fail" aria-hidden /> : <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-info" aria-hidden />}
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium text-body">{notice.title}</p>
                    <p className="mt-1 text-[13px] text-muted">{notice.body}</p>
                    {isFailed && notice.repositoryUrl != null && (
                        <a href={notice.repositoryUrl} data-testid="thread-open-repository" className="mt-3 inline-block rounded-card bg-fail px-3 py-1.5 text-[13px] font-medium text-white">
                            Open repository
                        </a>
                    )}
                </div>
            </div>
        </div>
    );
}
