import { Gauge } from 'lucide-react';
import type { ThreadNotice } from '@/types/threads';
import { useCountdown } from './useCountdown';

export function UsageLimitCard({ notice }: { notice: ThreadNotice }) {
    const countdown = useCountdown(notice.resetsAt);
    const resetTime = notice.resetsAt === null ? null : new Date(notice.resetsAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

    return (
        <div className="rounded-card bg-warn-soft p-4" data-testid="thread-notice" data-kind={notice.kind}>
            <div className="flex items-start gap-3">
                <Gauge className="mt-0.5 size-4 shrink-0 text-warn" aria-hidden />
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium text-body">{notice.title}</p>
                    <p className="mt-1 text-[13px] text-muted">{notice.body}</p>
                    {resetTime !== null && (
                        <p className="mt-3 text-[13px] text-body" data-testid="usage-reset">
                            Resets at {resetTime}
                            {countdown !== null && <span className="ml-2 tabular-nums text-muted" data-testid="usage-countdown">in {countdown}</span>}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
