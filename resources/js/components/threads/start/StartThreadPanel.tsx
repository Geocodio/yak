import { Link, router, usePage } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { Loader2, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { AccountDialog } from '@/components/accounts/AccountDialog';
import { YakMark } from '@/components/brand/YakMark';
import type { StartExample } from '@/components/start/StartFieldContext';
import { useDocsUrl } from '@/lib/docsUrl';
import { create as createRepository, edit as editRepository } from '@/routes/repos';
import { repositorySetup } from '@/routes/threads';
import type { SharedProps } from '@/types/shared';
import type { NewThreadPageData, RepositorySetupState } from '@/types/threads';
import { StartThreadForm } from './StartThreadForm';

type Props = Omit<NewThreadPageData, 'emailToYakAddress'> & {
    /** Closes the dialog, or leaves the page. Without it the "Not now" button is hidden. */
    onDismiss?: () => void;
    /** Props a partial reload fetches after a Claude account is connected. */
    reloadOnly: string[];
    onStarted?: () => void;
    autoFocus?: boolean;
    /** A request dropped into the composer by an example chip. */
    example?: StartExample;
};

type SetupSnapshot = { status: RepositorySetupState; step: string | null };

const POLL_INTERVAL_MS = 3000;

function BlockingState({ testId, title, body, children }: { testId: string; title: string; body: string; children: ReactNode }) {
    return (
        <div className="flex flex-col items-center px-6 py-10 text-center" data-testid={testId}>
            <YakMark className="size-14 drop-shadow-[0_6px_10px_rgba(26,26,31,0.14)]" />
            <h2 className="mt-4 text-[18px] font-semibold tracking-[-0.01em] text-body">{title}</h2>
            <p className="mt-2 max-w-[440px] text-[13.5px] leading-[1.55] text-muted">{body}</p>
            {children}
        </div>
    );
}

function NoRepositoryState({ onDismiss }: { onDismiss?: () => void }) {
    return (
        <BlockingState
            testId="new-thread-no-repository"
            title="Add a repository first"
            body="Every thread works in a repository. Yak clones it, runs it in a sandbox, and opens its pull requests there. Setup takes a few minutes, once."
        >
            <div className="mt-6 flex items-center gap-2">
                <Link href={createRepository.url()} data-testid="new-thread-add-repository">
                    <Button variant="primary" tabIndex={-1}>
                        Add repository
                    </Button>
                </Link>
                {onDismiss && (
                    <Button variant="secondary" onClick={onDismiss} data-testid="new-thread-not-now">
                        Not now
                    </Button>
                )}
            </div>
        </BlockingState>
    );
}

function NoAccountState({ onDismiss, onAddAccount }: { onDismiss?: () => void; onAddAccount: () => void }) {
    const docsUrl = useDocsUrl('claude-accounts/');

    return (
        <BlockingState
            testId="new-thread-no-account"
            title="Connect a Claude account"
            body="Threads run on a Claude account. Add your own, and your usage counts against your plan. Or ask an admin to add the shared Yak account."
        >
            <div className="mt-6 flex items-center gap-2">
                <Button variant="primary" onClick={onAddAccount} data-testid="new-thread-add-account">
                    Add your account
                </Button>
                {onDismiss && (
                    <Button variant="secondary" onClick={onDismiss} data-testid="new-thread-not-now">
                        Not now
                    </Button>
                )}
            </div>
            <a href={docsUrl} target="_blank" rel="noreferrer" className="mt-4 text-[12.5px] text-accent-text hover:underline">
                How accounts work
            </a>
        </BlockingState>
    );
}

function SetupStrip({ repo, snapshot }: { repo: string; snapshot: SetupSnapshot }) {
    if (snapshot.status === 'failed') {
        return (
            <div className="mb-3 flex flex-wrap items-center gap-3 rounded-card bg-fail-soft px-4 py-3" role="alert" data-testid="new-thread-repo-setup-failed">
                <TriangleAlert size={16} className="shrink-0 text-fail" aria-hidden="true" />
                <p className="min-w-0 flex-1 text-[13px] font-medium text-body">Setup failed for {repo}</p>
                <Link href={editRepository.url(repo)} data-testid="new-thread-open-repository">
                    <Button variant="secondary" tabIndex={-1}>
                        Open repository
                    </Button>
                </Link>
            </div>
        );
    }

    return (
        <div className="mb-3 flex items-start gap-3 rounded-card bg-info-soft px-4 py-3" data-testid="new-thread-repo-setting-up">
            <Loader2 size={16} className="mt-0.5 shrink-0 animate-spin text-info" aria-hidden="true" />
            <div className="min-w-0 flex-1">
                <p className="text-[13px] font-medium text-body">Setting up {repo}</p>
                <p className="mt-0.5 text-[12.5px] text-muted">
                    {snapshot.step !== null ? `${snapshot.step}.` : 'This takes a few minutes, once.'} Your thread starts as soon as it is ready.
                </p>
            </div>
        </div>
    );
}

/**
 * Wraps the start-a-thread form with the states in which a thread cannot
 * start yet: no repository, no usable Claude account, and a repository whose
 * setup is still running or has failed.
 */
export function StartThreadPanel({ onDismiss, reloadOnly, ...data }: Props) {
    const { setup } = usePage<SharedProps>().props;
    const [repo, setRepo] = useState(data.defaultRepo ?? '');
    const [isAccountDialogOpen, setIsAccountDialogOpen] = useState(false);
    const [liveBySlug, setLiveBySlug] = useState<Record<string, SetupSnapshot>>({});

    const selected = data.repos.find((candidate) => candidate.slug === repo);
    const snapshot: SetupSnapshot | null = selected ? (liveBySlug[selected.slug] ?? { status: selected.setupStatus, step: selected.setupStep }) : null;
    const isSettingUp = snapshot !== null && (snapshot.status === 'pending' || snapshot.status === 'running');
    const slug = selected?.slug;

    useEffect(() => {
        if (!isSettingUp || slug === undefined) {
            return;
        }
        let stopped = false;
        const poll = async () => {
            try {
                const response = await fetch(repositorySetup.url({ query: { repo: slug } }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (response.ok && !stopped) {
                    const next: SetupSnapshot = await response.json();
                    setLiveBySlug((current) => ({ ...current, [slug]: next }));
                    if (next.status === 'ready' || next.status === 'failed') {
                        return;
                    }
                }
            } catch {
                // A dropped poll is retried on the next tick.
            }
            if (!stopped) {
                timer = window.setTimeout(poll, POLL_INTERVAL_MS);
            }
        };
        let timer = window.setTimeout(poll, POLL_INTERVAL_MS);

        return () => {
            stopped = true;
            window.clearTimeout(timer);
        };
    }, [isSettingUp, slug]);

    const closeAccountDialog = useCallback(() => {
        setIsAccountDialogOpen(false);
        router.reload({ only: [...reloadOnly, 'setup'] });
    }, [reloadOnly]);

    if (data.repos.length === 0) {
        return <NoRepositoryState onDismiss={onDismiss} />;
    }

    if (data.picker.accounts.every((account) => account.usable === false)) {
        return (
            <>
                <NoAccountState onDismiss={onDismiss} onAddAccount={() => setIsAccountDialogOpen(true)} />
                {isAccountDialogOpen && setup !== null && <AccountDialog mode="add" models={setup.models} hasDefault={setup.hasOwnAccount} onClose={closeAccountDialog} />}
            </>
        );
    }

    return (
        <>
            {snapshot !== null && selected !== undefined && (isSettingUp || snapshot.status === 'failed') && <SetupStrip repo={selected.slug} snapshot={snapshot} />}
            <StartThreadForm {...data} onRepoChange={setRepo} isQueueing={isSettingUp} isRepoBlocked={snapshot?.status === 'failed'} />
        </>
    );
}
