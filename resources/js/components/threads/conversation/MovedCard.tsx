import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { show as showThread } from '@/routes/threads';
import type { ThreadNotice } from '@/types/threads';

/** The conversation card of a closed thread whose request continues in a new thread. */
export function MovedCard({ notice }: { notice: ThreadNotice }) {
    return (
        <div className="rounded-card bg-panel-2 p-4" data-testid="thread-notice-moved" data-kind={notice.kind}>
            <div className="flex items-start gap-3">
                <ArrowRight className="mt-0.5 size-4 shrink-0 text-muted" aria-hidden />
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium text-body">{notice.title}</p>
                    {notice.threadId !== undefined && (
                        <Link href={showThread(notice.threadId)} className="mt-1 inline-block text-[13px] text-body underline" data-testid="thread-moved-link">
                            {notice.threadTitle ?? notice.threadId}
                        </Link>
                    )}
                </div>
            </div>
        </div>
    );
}
