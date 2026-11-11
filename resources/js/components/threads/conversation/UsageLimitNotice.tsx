import { Link, usePage } from '@inertiajs/react';
import { Gauge } from 'lucide-react';
import { newMethod } from '@/routes/threads';
import type { PageProps } from '@/types/shared';
import type { ThreadNotice as ThreadNoticeData, ThreadPageData } from '@/types/threads';

function resetSentence(resetsAt: string): string {
    const resets = new Date(resetsAt);
    const minutes = Math.max(1, Math.round((resets.getTime() - Date.now()) / 60000));
    const duration = minutes >= 90 ? `${Math.round(minutes / 60)} hours` : minutes === 1 ? '1 minute' : `${minutes} minutes`;

    return `It resets at ${resets.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}, in about ${duration}.`;
}

/** The account hit its usage limit: say when it resets and offer a fresh thread on another account. */
export function UsageLimitNotice({ notice }: { notice: ThreadNoticeData; threadId: number }) {
    const { thread } = usePage<PageProps<ThreadPageData>>().props;

    return (
        <div className="rounded-card bg-warn-soft p-4" data-testid="thread-notice" data-kind="usage_limit">
            <div className="flex items-start gap-3">
                <span className="grid size-8 shrink-0 place-items-center rounded-chip bg-panel text-warn" aria-hidden>
                    <Gauge size={16} />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium text-body">Usage limit reached</p>
                    <p className="mt-1 text-[13px] text-muted">
                        {notice.body}
                        {notice.resetsAt && ` ${resetSentence(notice.resetsAt)}`}
                    </p>
                    <Link
                        href={newMethod.url({ query: { repo: thread.repo } })}
                        className="mt-3 inline-block rounded-card border border-hair bg-panel px-3 py-1.5 text-[13px] font-medium text-body hover:bg-panel-2"
                        data-testid="usage-limit-new-thread"
                    >
                        Start a new thread on another account
                    </Link>
                </div>
            </div>
        </div>
    );
}
