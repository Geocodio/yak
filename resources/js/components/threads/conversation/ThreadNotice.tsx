import type { ThreadNotice as ThreadNoticeData } from '@/types/threads';
import { AccountProblemCard } from './AccountProblemCard';
import { GuardStopCard } from './GuardStopCard';
import { RebuildCard } from './RebuildCard';
import { UsageLimitCard } from './UsageLimitCard';
import { WaitingCard } from './WaitingCard';

export function ThreadNotice({ notice, threadId, repo, branch, readonly = false }: { notice: ThreadNoticeData; threadId: number; repo: string; branch: string | null; readonly?: boolean }) {
    if (notice.kind === 'restoring' || notice.kind === 'restore_failed') {
        return <RebuildCard notice={notice} threadId={threadId} repo={repo} branch={branch} readonly={readonly} />;
    }

    if (notice.kind === 'waiting_for_account' || notice.kind === 'waiting_for_sandbox') {
        return <WaitingCard notice={notice} threadId={threadId} readonly={readonly} />;
    }

    if (notice.kind === 'account_invalid' || notice.kind === 'account_expired' || notice.kind === 'credential_exposure') {
        return <AccountProblemCard notice={notice} />;
    }

    if (notice.kind === 'usage_limit') {
        return <UsageLimitCard notice={notice} />;
    }

    return <GuardStopCard notice={notice} threadId={threadId} readonly={readonly} />;
}
