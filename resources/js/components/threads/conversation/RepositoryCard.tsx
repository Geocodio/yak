import { Button, cn } from '@geocodio/console-ui';
import type { RepositoryCardState } from '@/types/thread-events';
import type { ConversationItem } from './conversationItem';

const DOT: Record<RepositoryCardState, string> = {
    running: 'bg-brand',
    setup: 'bg-brand',
    done: 'bg-ok',
    setup_failed: 'bg-fail',
    no_access: 'bg-fail',
};

const LABEL: Record<RepositoryCardState, string> = {
    running: 'Cloning',
    setup: 'Running setup',
    done: 'Ready',
    setup_failed: 'Setup failed',
    no_access: 'No access',
};

/**
 * One repository Claude added, in the conversation. The recovery actions arrive as callbacks
 * so the card shows a button only when the page can act on it.
 */
export function RepositoryCard({
    item,
    onRetrySetup,
    onMakeContextOnly,
}: {
    item: ConversationItem;
    onRetrySetup?: (repo: string) => void;
    onMakeContextOnly?: (repo: string) => void;
}) {
    const card = item.repository;

    if (!card) {
        return null;
    }

    return (
        <div className="ml-9 rounded-card border border-hair bg-panel px-3 py-2.5 text-[13px]" data-testid={`repository-card-${card.repo}`} data-state={card.state}>
            <div className="flex flex-wrap items-center gap-2">
                <span aria-hidden className={cn('inline-block h-2 w-2 shrink-0 rounded-pill', DOT[card.state])} />
                <span className="font-medium text-body">{card.repo}</span>
                <span className="rounded-pill border border-hair bg-panel-2 px-2 py-0.5 text-[11px] text-muted" data-testid="repository-card-role">
                    {card.role === 'context' ? 'Context only' : 'Changes'}
                </span>
                <span className="ml-auto text-[12px] text-muted">{LABEL[card.state]}</span>
            </div>

            {card.reason && <p className="mt-1 text-[12px] text-muted">{card.reason}</p>}
            {card.path && <p className="mt-1 truncate font-mono text-[11px] text-muted">{card.path}</p>}
            {card.state === 'done' && card.setupSeconds !== null && <p className="mt-1 text-[12px] text-muted">Ready in {card.setupSeconds}s</p>}
            {card.error && (
                <p className="mt-2 whitespace-pre-wrap text-[12.5px] text-fail" data-testid="repository-card-error">
                    {card.error}
                </p>
            )}
            {card.state === 'setup_failed' && card.output && (
                <pre className="mt-2 max-h-40 overflow-auto rounded-card bg-panel-2 p-2 font-mono text-[11px] text-body" data-testid="repository-card-output">
                    {card.output}
                </pre>
            )}

            {card.state === 'setup_failed' && (onRetrySetup || onMakeContextOnly) && (
                <div className="mt-2 flex flex-wrap items-center gap-2">
                    {onRetrySetup && (
                        <Button variant="secondary" onClick={() => onRetrySetup(card.repo)} data-testid="repository-retry-setup">
                            Retry setup
                        </Button>
                    )}
                    {onMakeContextOnly && card.role === 'changes' && (
                        <Button variant="tertiary" onClick={() => onMakeContextOnly(card.repo)} data-testid="repository-make-context">
                            Make it context only
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}
