import { Lock, Users } from 'lucide-react';
import type { ThreadData } from '@/types/threads';

/** "Private" until the owner shares it; "Shared" once anyone else can read it. Hidden for a thread nobody owns. */
export function VisibilityBadge({ thread }: { thread: ThreadData }) {
    const isPrivateToOwner = thread.visibility === 'private' && thread.isOwner && thread.sharedWithCount === 0;
    const isShared = thread.visibility === 'private' ? !isPrivateToOwner : thread.isOwner;

    if (!isPrivateToOwner && !isShared) {
        return null;
    }

    return (
        <span
            data-testid="visibility-badge"
            className="inline-flex items-center gap-1 rounded-pill border border-hair bg-panel-2 px-2 py-0.5 text-[11px] font-medium text-muted"
        >
            {isPrivateToOwner ? <Lock size={11} aria-hidden="true" /> : <Users size={11} aria-hidden="true" />}
            {isPrivateToOwner ? 'Private' : thread.visibility === 'private' ? `Shared with ${thread.sharedWithCount} ${thread.sharedWithCount === 1 ? 'person' : 'people'}` : 'Shared'}
        </span>
    );
}
