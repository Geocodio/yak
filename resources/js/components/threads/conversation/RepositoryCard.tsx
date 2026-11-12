import { Button, cn } from '@geocodio/console-ui';
import { Link, usePage } from '@inertiajs/react';
import { FolderGit2 } from 'lucide-react';
import repos from '@/routes/repos';
import type { SharedProps } from '@/types/shared';
import type { RepositoryCardState } from '@/types/thread-events';
import type { ConversationItem } from './conversationItem';
import { RepositoryCardSteps } from './RepositoryCardSteps';

const HEADLINE: Record<RepositoryCardState, string> = {
    running: 'Adding a repository',
    setup: 'Adding a repository',
    done: 'Added a repository',
    setup_failed: 'Added a repository',
    no_access: 'Could not add a repository',
};

const STATUS: Record<RepositoryCardState, string> = {
    running: 'Cloning',
    setup: 'Running setup',
    done: 'Added',
    setup_failed: 'Added, setup failed',
    no_access: 'Not added',
};

const PILL: Record<RepositoryCardState, string> = {
    running: 'bg-panel-2 text-muted',
    setup: 'bg-panel-2 text-muted',
    done: 'bg-ok-soft text-ok',
    setup_failed: 'bg-warn-soft text-warn',
    no_access: 'bg-fail-soft text-fail',
};

const DOT: Record<RepositoryCardState, string> = { running: 'bg-brand', setup: 'bg-brand', done: 'bg-ok', setup_failed: 'bg-warn', no_access: 'bg-fail' };

/**
 * One repository Claude added, in the conversation. The recovery actions arrive as callbacks
 * so the card shows a button only when the page can act on it.
 */
export function RepositoryCard({
    item,
    onRetrySetup,
    onMakeContextOnly,
    onRemove,
}: {
    item: ConversationItem;
    onRetrySetup?: (repo: string) => void;
    onMakeContextOnly?: (repo: string) => void;
    onRemove?: (repo: string) => void;
}) {
    const card = item.repository;
    const { docs } = usePage<SharedProps>().props;

    if (!card) {
        return null;
    }

    const showsPill = card.state === 'running' || card.state === 'setup';
    const makeContext = onMakeContextOnly && card.role === 'changes' && (card.state === 'setup_failed' || card.state === 'done');

    return (
        <div className="ml-9 rounded-card border border-hair bg-panel px-3 py-2.5 text-[13px]" data-testid={`repository-card-${card.repo}`} data-state={card.state}>
            <div className="flex items-start gap-2.5">
                <span aria-hidden className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-control bg-panel-2 text-muted">
                    <FolderGit2 className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-muted">{HEADLINE[card.state]}</span>
                        <span className="font-medium text-body">{card.repo}</span>
                        <span className="rounded-pill border border-hair bg-panel-2 px-2 py-0.5 text-[11px] text-muted" data-testid="repository-card-role">
                            {card.role === 'context' ? 'Context' : 'Changes'}
                        </span>
                    </div>
                    <p className="mt-0.5 truncate text-[12px] text-muted">
                        add_repository by Claude{card.path && <> · <span className="font-mono text-[11px]">{card.path}</span></>}
                    </p>
                </div>
                <span className={cn('inline-flex shrink-0 items-center gap-1.5 rounded-pill px-2 py-0.5 text-[12px]', PILL[card.state])} data-testid="repository-card-status">
                    {showsPill && <span aria-hidden className={cn('inline-block size-1.5 rounded-pill', DOT[card.state])} />}
                    {STATUS[card.state]}
                </span>
            </div>

            {card.reason && (
                <p className="mt-2 text-[12.5px] text-body">
                    <span className="text-muted">Why:</span> {card.reason}
                </p>
            )}

            <RepositoryCardSteps card={card} />

            {card.state === 'setup_failed' && (
                <div className="mt-2 rounded-control bg-warn-soft px-3 py-2 text-[12.5px]" data-testid="repository-card-error">
                    <p className="font-medium text-body">Setup did not finish.</p>
                    <p className="mt-0.5 text-muted">The repo is cloned and Claude can read it, but tests and builds there may fail until dependencies are installed.</p>
                </div>
            )}
            {card.state === 'no_access' && (
                <div className="mt-2 rounded-control bg-warn-soft px-3 py-2 text-[12.5px]" data-testid="repository-card-error">
                    <p className="font-medium text-body">Yak cannot clone {card.repo}.</p>
                    {card.error && <p className="mt-0.5 whitespace-pre-wrap text-muted">{card.error}</p>}
                </div>
            )}

            <div className="mt-2 flex flex-wrap items-center gap-2">
                {card.state === 'setup_failed' && onRetrySetup && (
                    <Button variant="secondary" onClick={() => onRetrySetup(card.repo)} data-testid="repository-retry-setup">
                        Retry setup
                    </Button>
                )}
                {makeContext && (
                    <Button variant="tertiary" onClick={() => onMakeContextOnly(card.repo)} data-testid="repository-make-context">
                        Make it context only
                    </Button>
                )}
                {(card.state === 'setup_failed' || card.state === 'done') && onRemove && (
                    <Button variant="tertiary" onClick={() => onRemove(card.repo)} data-testid="repository-remove">
                        Remove
                    </Button>
                )}
                {card.state === 'no_access' && (
                    <>
                        <Link href={repos.edit.url(card.repo)} className="inline-flex items-center rounded-control border border-hair bg-panel px-3 py-1.5 text-[12.5px] font-medium text-body hover:bg-panel-2" data-testid="repository-open-settings">
                            Open repository settings
                        </Link>
                        <a href={docs.baseUrl} target="_blank" rel="noreferrer" className="text-[12.5px] text-accent-text hover:underline">
                            How Yak gets access
                        </a>
                    </>
                )}
                {card.state === 'done' && <span className="ml-auto text-[12px] text-muted">Also listed under Environment</span>}
            </div>
        </div>
    );
}
