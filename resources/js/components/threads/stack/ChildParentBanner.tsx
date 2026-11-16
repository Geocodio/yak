import { Link } from '@inertiajs/react';
import { show } from '@/routes/threads';
import type { ThreadPageData } from '@/types/threads';

/** Shown on a sub-thread's page: who it reports to. Hidden on every other thread. */
export function ChildParentBanner({ thread, parent, hasBrief = false }: { thread: ThreadPageData['thread']; parent: ThreadPageData['stackParent']; hasBrief?: boolean }) {
    if (thread.parentThreadId === null || parent === null) {
        return null;
    }

    return (
        <p data-testid="child-parent-banner" className="shrink-0 border-b border-hair bg-panel-2 px-4 py-2 text-[12.5px] text-muted sm:px-8">
            {!hasBrief && (
                <>
                    Sub-thread of{' '}
                    <Link href={show.url(parent.id)} className="font-medium text-accent-text hover:underline">{parent.title}</Link>.{' '}
                </>
            )}
            It can message its parent and its siblings, but cannot start sub-threads of its own.
        </p>
    );
}
