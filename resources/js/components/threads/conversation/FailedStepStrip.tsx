import { rebuildProgress } from '@/threads/threadStatus';
import type { ThreadNotice } from '@/types/threads';
import { RetryRestoreButton } from './RestoreProgress';

/** Phone-only strip that keeps a failed rebuild in view while the conversation scrolls. */
export function FailedStepStrip({ notice, threadId, readonly }: { notice: ThreadNotice | null; threadId: string; readonly: boolean }) {
    const progress = notice === null ? null : rebuildProgress(notice);

    if (notice?.kind !== 'restore_failed' || progress === null) {
        return null;
    }

    return (
        <div className="sticky top-0 z-10 flex items-center justify-between gap-3 bg-fail-soft px-4 py-2 lg:hidden" data-testid="failed-step-strip">
            <span className="min-w-0 text-[13px] font-medium text-fail">
                Step {progress.current} of {progress.total} failed
            </span>
            {!readonly && <RetryRestoreButton threadId={threadId} testId="strip-restore-retry" className="shrink-0 px-2.5 py-1 text-[12px]">Retry</RetryRestoreButton>}
        </div>
    );
}
