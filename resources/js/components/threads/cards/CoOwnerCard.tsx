import { CoOwnerPanel } from '@/components/shared/CoOwnerPanel';
import { approve, checkAccess, reject, requestChanges, withdraw } from '@/routes/threads/co-owner';
import type { CoOwnerData } from '@/types/coOwner';

/** The held change as the card above the composer: the asked co-owner decides, the requester waits or withdraws. */
export function CoOwnerCard({ threadId, coOwner, repoUrl, onOpenChanges }: { threadId: string; coOwner: CoOwnerData; repoUrl: string | null; onOpenChanges: () => void }) {
    return (
        <CoOwnerPanel
            variant="card"
            draftThreadId={threadId}
            coOwner={coOwner}
            repoUrl={repoUrl}
            onOpenChanges={onOpenChanges}
            shareUrl={`${window.location.origin}/threads/${threadId}#card-co-owner`}
            routes={{ approve: approve.url(threadId), requestChanges: requestChanges.url(threadId), reject: reject.url(threadId), withdraw: withdraw.url(threadId), checkAccess: checkAccess.url(threadId) }}
        />
    );
}
