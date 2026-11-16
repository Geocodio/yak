import type { ThreadData } from '@/types/threads';

export function PrivateThreadBanner({ thread }: { thread: ThreadData }) {
    if (thread.visibility !== 'private' || !thread.isOwner || thread.sharedWithCount > 0) {
        return null;
    }

    return (
        <p data-testid="private-banner" className="shrink-0 border-b border-hair bg-panel-2 px-4 py-2 text-[12.5px] text-muted sm:px-8">
            Only you can read this thread. It holds everything Claude read on your computer, so Yak keeps it private until you share it.
        </p>
    );
}
