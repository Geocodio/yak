import { cn } from '@geocodio/console-ui';
import { threadStatus } from '@/threads/threadStatus';
import type { ThreadComposer, ThreadNotice } from '@/types/threads';

const TONE = {
    info: 'bg-info-soft text-info',
    warn: 'bg-warn-soft text-warn',
    fail: 'bg-fail-soft text-fail',
    brand: 'bg-brand-soft text-brand',
} as const;

export function StatusPill({ notice, composerState }: { notice: ThreadNotice | null; composerState: ThreadComposer['state'] }) {
    const status = threadStatus(notice, composerState);

    if (status === null) {
        return null;
    }

    return (
        <span className={cn('inline-flex shrink-0 items-center rounded-pill px-2 py-0.5 text-[12px] font-medium', TONE[status.tone])} data-testid="thread-status-pill">
            {status.label}
        </span>
    );
}
