import { router } from '@inertiajs/react';
import { OctagonPause } from 'lucide-react';
import { continueMethod } from '@/routes/threads';
import type { ThreadNotice } from '@/types/threads';

export function GuardStopCard({ notice, threadId, readonly }: { notice: ThreadNotice; threadId: string; readonly: boolean }) {
    return (
        <div className="rounded-card bg-warn-soft p-4" data-testid="thread-notice" data-kind={notice.kind}>
            <div className="flex items-start gap-3">
                <OctagonPause className="mt-0.5 size-4 shrink-0 text-warn" aria-hidden />
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium text-body">{notice.title}</p>
                    <p className="mt-1 text-[13px] text-muted">{notice.body}</p>
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
