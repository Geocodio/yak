import { cn } from '@geocodio/console-ui';
import { ChevronRight, Laptop } from 'lucide-react';
import { Fragment, useState } from 'react';
import { fetchJsonWithRetry } from '@/lib/fetchJsonWithRetry';
import type { LocalTurn, LocalTurnsData, ThreadMessage } from '@/types/threads';
import { ClaudeMessage } from './ClaudeMessage';
import { DayDivider } from './DayDivider';
import { PersonMessage } from './PersonMessage';
import { ToolSteps } from './ToolSteps';

const PAGE_SIZE = 25;

function formatRange(startedAt: string, endedAt: string): string {
    const day = (iso: string) => new Date(iso).toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
    const time = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

    return day(startedAt) === day(endedAt) ? `${day(startedAt)}, ${time(startedAt)} to ${time(endedAt)}` : `${day(startedAt)} to ${day(endedAt)}`;
}

function personMessageOf(turn: LocalTurn, author: Pick<LocalTurnsData, 'authorName' | 'authorInitials'>): ThreadMessage {
    return {
        id: `local-turn-${turn.id}`,
        uuid: null,
        text: turn.text,
        authorName: author.authorName,
        authorInitials: author.authorInitials,
        model: null,
        state: 'read',
        intent: null,
        sentAt: turn.at,
        readAfterSequence: null,
        attachmentIds: [],
        bodyHtml: turn.html,
        attachments: [],
    };
}

/**
 * The turns that ran on the person's computer before the thread moved to Yak:
 * one row while folded, a tinted read-only region when open. The newest ten
 * come with the page; earlier ones load in pages of 25.
 */
export function LocalTurnsFold({ threadId, localTurns, modelLabels = {} }: { threadId: string; localTurns: LocalTurnsData; modelLabels?: Record<string, string> }) {
    const [isOpen, setIsOpen] = useState(false);
    const [turns, setTurns] = useState<LocalTurn[]>(localTurns.turns);
    const [hasMore, setHasMore] = useState(localTurns.hasMore);
    const [isLoading, setIsLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const { count, firstPromptText, startedAt, endedAt } = localTurns;
    const range = formatRange(startedAt, endedAt);

    const loadEarlier = () => {
        setIsLoading(true);
        setFailed(false);
        fetchJsonWithRetry<{ turns: LocalTurn[]; hasMore: boolean }>(`/threads/${threadId}/local-turns?before=${turns[0].id}&limit=${PAGE_SIZE}`)
            .then((page) => {
                setTurns((current) => [...page.turns, ...current]);
                setHasMore(page.hasMore);
            })
            .catch(() => setFailed(true))
            .finally(() => setIsLoading(false));
    };

    let previousDay = '';

    return (
        <div className={cn('rounded-xl', isOpen ? 'border border-hair bg-panel' : 'bg-panel-2')} data-testid="local-turns-fold" data-open={isOpen}>
            <button
                type="button"
                aria-expanded={isOpen}
                onClick={() => setIsOpen((value) => !value)}
                className={cn('flex w-full min-w-0 cursor-pointer items-center gap-2.5 rounded-xl px-3 py-2.5 text-left', !isOpen && 'hover:bg-hair')}
                data-testid="local-turns-toggle"
            >
                <span className={cn('flex h-6 w-6 flex-none items-center justify-center rounded-pill border border-hair text-muted', isOpen ? 'bg-panel-2' : 'bg-panel')}>
                    <Laptop size={13} aria-hidden />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block text-[12px] font-medium text-body">
                        {count.toLocaleString()} {count === 1 ? 'turn' : 'turns'} on your computer{isOpen ? '' : ' before you moved to Yak'}
                    </span>
                    <span className="block truncate text-[12px] text-muted">{isOpen ? range : `“${firstPromptText}” · ${range}`}</span>
                </span>
                <span className="inline-flex flex-none items-center gap-1 text-[12px] text-muted">
                    {isOpen ? 'Hide' : 'Show'}
                    <ChevronRight size={12} className={cn('transition-transform duration-150', isOpen && 'rotate-90')} aria-hidden />
                </span>
            </button>
            {isOpen && (
                <div className="flex flex-col gap-4 border-t border-hair px-3 pt-3 pb-3.5">
                    {hasMore && (
                        <button type="button" onClick={loadEarlier} disabled={isLoading} className="cursor-pointer self-start py-[3px] text-[12px] font-medium text-accent-text hover:underline disabled:opacity-60" data-testid="local-turns-load-earlier">
                            {isLoading ? 'Loading earlier turns…' : failed ? 'Could not load. Try again' : `Load ${Math.min(PAGE_SIZE, count - turns.length).toLocaleString()} earlier turns`}
                        </button>
                    )}
                    {turns.map((turn) => {
                        const day = turn.at.slice(0, 10);
                        const showDay = day !== previousDay;
                        previousDay = day;

                        return (
                            <Fragment key={turn.id}>
                                {showDay && <DayDivider iso={turn.at} />}
                                <PersonMessage message={personMessageOf(turn, localTurns)} pending={false} origin="local" modelLabels={modelLabels} onOpenMedia={() => undefined} />
                                {turn.items.map((item) =>
                                    item.kind === 'text' ? (
                                        <ClaudeMessage key={item.key} modelLabels={modelLabels} item={{ kind: 'text', key: item.key, sequence: 0, role: 'assistant', html: item.html, model: item.model ?? undefined }} />
                                    ) : (
                                        <ToolSteps key={item.key} live={false} item={{ kind: 'tools', key: item.key, sequence: 0, role: 'assistant', steps: item.steps }} />
                                    ),
                                )}
                            </Fragment>
                        );
                    })}
                    <p className="text-center text-[11px] text-faint">{hasMore ? `Showing the latest ${turns.length.toLocaleString()} of ${count.toLocaleString()}` : `That is all ${count.toLocaleString()} ${count === 1 ? 'turn' : 'turns'} from your computer. Read only.`}</p>
                </div>
            )}
        </div>
    );
}
