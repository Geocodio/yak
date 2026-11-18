import { CoOwnerCard } from '@/components/threads/cards/CoOwnerCard';
import { EarlyWarningCard } from '@/components/threads/cards/EarlyWarningCard';
import { RepositoryQuestionCard } from '@/components/threads/cards/RepositoryQuestionCard';
import { StopCard } from '@/components/threads/cards/StopCard';
import { edit as editRepository } from '@/routes/repos';
import type { ThreadPageData } from '@/types/threads';

type Props = {
    page: Pick<ThreadPageData, 'thread' | 'card' | 'repositoryQuestion' | 'earlyWarning' | 'coOwner' | 'stopped' | 'actions'>;
    onOpenChanges: () => void;
    onMove: () => void;
    /** Opens the log of the latest run; null when the thread has no run. */
    onShowLog: (() => void) | null;
};

/** The one card above the composer: the repository question, the early warning, the co-owner decision or the stop. The question form lives in the conversation. */
export function ThreadCards({ page, onOpenChanges, onMove, onShowLog }: Props) {
    const { thread, card, repositoryQuestion, earlyWarning, coOwner, stopped, actions } = page;
    let content = null;
    let anchor = '';

    if (card === 'repository_question' && repositoryQuestion !== null) {
        anchor = 'card-repository-question';
        content = <RepositoryQuestionCard threadId={thread.id} question={repositoryQuestion} />;
    } else if (card === 'early_warning' && earlyWarning !== null) {
        anchor = 'card-early-warning';
        content = <EarlyWarningCard threadId={thread.id} warning={earlyWarning} />;
    } else if (card === 'co_owner' && coOwner !== null) {
        anchor = 'card-co-owner';
        content = <CoOwnerCard threadId={thread.id} coOwner={coOwner} repoUrl={thread.repo === 'unknown' ? null : editRepository.url(thread.repo)} onOpenChanges={onOpenChanges} />;
    } else if (card === 'stopped' && stopped !== null) {
        anchor = 'card-stop';
        content = <StopCard threadId={thread.id} stopped={stopped} actions={actions} onMove={onMove} onShowLog={onShowLog} title={coOwner?.status === 'expired' ? 'The co-owner request expired' : undefined} />;
    }

    if (content === null) {
        return null;
    }

    return (
        <div className="shrink-0 bg-app px-4 pt-3 sm:px-8" data-testid="thread-cards">
            <div id={anchor} className="mx-auto max-w-[820px]">{content}</div>
        </div>
    );
}
