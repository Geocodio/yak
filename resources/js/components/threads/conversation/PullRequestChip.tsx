import { Badge } from '@geocodio/console-ui';
import { GitPullRequest } from 'lucide-react';
import { show } from '@/routes/threads/pull-requests';
import { useJson } from '@/threads/panels/useJson';
import type { ThreadPullRequestsData } from '@/types/thread-pull-requests';

const CI_LABEL: Record<string, string> = { passing: 'CI passing', failing: 'CI failing', running: 'CI running' };
const CI_TONE: Record<string, 'ok' | 'fail' | 'info'> = { passing: 'ok', failing: 'fail', running: 'info' };

/** The thread's pull requests as chips under the conversation, fed by the Pull requests panel endpoint. */
export function PullRequestChips({ threadId, lastActivityAt }: { threadId: string; lastActivityAt: string | null }) {
    const { data } = useJson<ThreadPullRequestsData>(`${show.url(threadId)}?at=${encodeURIComponent(lastActivityAt ?? '')}`);

    if (data === null || data.pullRequests.length === 0) {
        return null;
    }

    return (
        <div className="ml-9 flex flex-col items-start gap-2" data-testid="conversation-pull-requests">
            {data.pullRequests.map((row) => (
                <a key={row.rowId} href={row.url} target="_blank" rel="noreferrer" data-testid={`conversation-pr-chip-${row.repo}`} className="flex items-center gap-2 rounded-card border border-hair bg-panel px-3 py-2 text-[13px] text-body hover:bg-panel-2">
                    <GitPullRequest size={13} aria-hidden className="text-muted" />
                    <span className="font-mono">{row.repo}{row.number !== null ? `#${row.number}` : ''}</span>
                    <span className="text-muted">{row.title}</span>
                    {row.ci && <Badge tone={CI_TONE[row.ci.status] ?? 'neutral'}>{CI_LABEL[row.ci.status] ?? row.ci.status}</Badge>}
                </a>
            ))}
        </div>
    );
}
