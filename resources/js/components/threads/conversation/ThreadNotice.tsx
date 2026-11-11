import { router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { continueMethod } from '@/routes/threads';
import type { ThreadNotice as ThreadNoticeData } from '@/types/threads';
import { RestoreProgress } from './RestoreProgress';
import { UsageLimitNotice } from './UsageLimitNotice';

export function ThreadNotice({ notice, threadId, readonly = false }: { notice: ThreadNoticeData; threadId: number; readonly?: boolean }) {
    if (notice.kind === 'usage_limit') {
        return <UsageLimitNotice notice={notice} threadId={threadId} />;
    }

    return (
        <div className="rounded-card bg-warn-soft p-4" data-testid="thread-notice" data-kind={notice.kind}>
            <div className="flex items-start gap-3">
                <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warn" aria-hidden />
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium text-body">{notice.title}</p>
                    <p className="mt-1 text-[13px] text-muted">{notice.body}</p>
                    {notice.restoreSteps.length > 0 && <RestoreProgress steps={notice.restoreSteps} threadId={threadId} readonly={readonly} />}
                    {notice.canContinue && !readonly && (
                        <button
                            type="button"
                            data-testid="thread-continue"
                            onClick={() => router.post(continueMethod.url(threadId), {}, { preserveScroll: true })}
                            className="mt-3 rounded-card bg-accent px-3 py-1.5 text-[13px] font-medium text-white"
                        >
                            Continue
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
