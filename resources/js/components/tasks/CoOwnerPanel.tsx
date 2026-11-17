import { CoOwnerPanel as SharedCoOwnerPanel } from '@/components/shared/CoOwnerPanel';
import { approve, checkAccess, reject, requestChanges, withdraw } from '@/routes/tasks/co-owner';
import type { CoOwnerData } from '@/types/coOwner';

/** The held-change panel in the clarification slot of the task page. */
export function CoOwnerPanel({ taskId, coOwner, repoUrl }: { taskId: string; coOwner: CoOwnerData; repoUrl: string | null }) {
    return (
        <SharedCoOwnerPanel
            coOwner={coOwner}
            repoUrl={repoUrl}
            shareUrl={`${window.location.origin}/tasks/${taskId}#co-owner`}
            routes={{ approve: approve.url(taskId), requestChanges: requestChanges.url(taskId), reject: reject.url(taskId), withdraw: withdraw.url(taskId), checkAccess: checkAccess.url(taskId) }}
        />
    );
}
