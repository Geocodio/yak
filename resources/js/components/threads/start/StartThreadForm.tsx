import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Button, Kbd, Menu, cn } from '@geocodio/console-ui';
import { ChevronDown, Search, Wrench } from 'lucide-react';
import { useCallback, useRef, useState, type KeyboardEvent } from 'react';
import { AttachButton, AttachmentDropOverlay, DraftAttachments } from '@/components/attachments/Attachments';
import { AttachmentTextarea, type AttachmentTextareaHandle } from '@/components/attachments/AttachmentTextarea';
import { useAttachmentDraft } from '@/components/attachments/useAttachmentDraft';
import { repoInitials } from './repoInitials';
import { RunsOnStrip } from './RunsOnStrip';
import { StartHints } from './StartHints';
import { RepositoryPicker } from './RepositoryPicker';
import { RepositoryProgress } from './RepositoryProgress';
import { show, store } from '@/routes/threads';
import { claudeAccounts } from '@/routes/settings';
import type { SharedProps } from '@/types/shared';
import type { NewThreadPageData, StartRepository } from '@/types/threads';

type Mode = 'fix' | 'research';

const MODES: { mode: Mode; label: string; icon: typeof Wrench; hint: string }[] = [
    { mode: 'fix', label: 'Change', icon: Wrench, hint: 'Yak makes the change and opens a PR for review.' },
    { mode: 'research', label: 'Research', icon: Search, hint: 'Yak investigates and writes a report. No PR.' },
];

/**
 * The start-a-thread form shared by the Threads/New page and the dialog:
 * description, repo, mode, attachments, and the "Runs on / with" account and
 * model pickers. Changing the repo re-resolves the defaults from
 * `picker.defaultsByRepo`.
 */
export function StartThreadForm({ repos, picker, defaultRepo, onStarted, autoFocus }: Omit<NewThreadPageData, 'emailToYakAddress'> & { onStarted?: () => void; autoFocus?: boolean }) {
    const { docs } = usePage<SharedProps>().props;
    const form = useForm<{ repo: string; mode: Mode; description: string; claude_account_id: string; model: string | null; attachments: File[]; attachment_refs: string[]; repositories: StartRepository[] }>({
        repo: defaultRepo ?? '',
        mode: 'fix',
        description: '',
        claude_account_id: '',
        model: null,
        attachments: [],
        attachment_refs: [],
        repositories: [],
    });
    const [startedThreadId, setStartedThreadId] = useState<number | null>(null);
    const editorRef = useRef<AttachmentTextareaHandle>(null);
    const draft = useAttachmentDraft({ enabled: true, onAdd: (added) => editorRef.current?.insertTokens(added) });
    const errors = form.errors as Record<string, string | undefined>;

    const noAccounts = picker.accounts.every((account) => account.usable === false);
    const noRepos = repos.length === 0;
    const defaults = picker.defaultsByRepo[form.data.repo];
    const defaultAccount = picker.accounts.find((account) => account.id === defaults?.accountId);
    const chosenAccount = picker.accounts.find((account) => String(account.id) === form.data.claude_account_id);
    const effectiveModel = form.data.model ?? chosenAccount?.defaultModel ?? defaults?.model ?? null;
    const canSubmit = !noAccounts && !noRepos && form.data.repo !== '' && form.data.description.trim().length >= 3;

    const extras = form.data.repositories;
    const isResearch = form.data.mode === 'research';
    const contextRepos = extras.filter((extra) => isResearch || extra.role === 'context');
    const expectedPullRequests = 1 + extras.filter((extra) => extra.role === 'changes').length;
    const openThread = useCallback(() => {
        if (startedThreadId !== null) {
            router.visit(show.url(startedThreadId));
        }
    }, [startedThreadId]);

    const submit = () => {
        if (!canSubmit || form.processing) {
            return;
        }
        form.transform((data) => ({
            ...data,
            claude_account_id: data.claude_account_id === '' ? null : data.claude_account_id,
            attachments: draft.files,
            attachment_refs: draft.refs,
        }));
        form.post(store.url(), {
            onSuccess: (page) => {
                const startedId = (page.props as unknown as SharedProps).flash.startedThreadId;
                if (startedId) {
                    setStartedThreadId(startedId);
                } else {
                    onStarted?.();
                }
            },
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            event.preventDefault();
            submit();
        }
    };

    const error = Object.entries(errors).find(([key]) => key.startsWith('repositories'))?.[1] ?? errors.claude_account_id ?? errors.repo ?? errors.description ?? errors.model ?? errors.mode;

    return (
        <div data-testid="start-thread-form">
            <div className="relative overflow-hidden rounded-card border border-hair bg-panel shadow-card focus-within:border-accent" {...draft.dropzoneProps}>
                <AttachmentDropOverlay draft={draft} />
                <AttachmentTextarea
                    ref={editorRef}
                    draft={draft}
                    rows={5}
                    autoFocus={autoFocus}
                    placeholder="What should Yak do? Say what is wrong or what you want, and where to look if you know."
                    value={form.data.description}
                    onValueChange={(value) => form.setData('description', value)}
                    onKeyDown={onKeyDown}
                    onPaste={draft.onPaste}
                    aria-invalid={errors.description ? true : undefined}
                    className="w-full resize-none border-0 shadow-none focus:ring-0"
                    data-testid="start-thread-description"
                />
                <DraftAttachments draft={draft} errors={errors} progress={form.processing && draft.items.length > 0 ? (form.progress?.percentage ?? 0) : null} onOpenMedia={() => {}} />
                <div className="flex flex-wrap items-center gap-2 border-t border-hair px-3 py-2">
                    <Menu
                        aria-label="Repository"
                        disabled={noRepos}
                        trigger={
                            <span
                                className={cn(
                                    'inline-flex h-7 items-center gap-1.5 rounded-control border bg-panel pl-2 pr-2.5 text-[12.5px] font-medium hover:bg-panel-2',
                                    form.data.repo === '' ? 'border-dashed border-hair-strong text-muted' : 'border-hair-strong text-body',
                                )}
                                data-testid="start-thread-repo"
                            >
                                <span className={cn('grid size-4 place-items-center rounded-[4px] font-mono text-[9px] font-semibold', form.data.repo === '' ? 'bg-panel-2 text-faint' : 'bg-brand text-accent-ink')}>
                                    {form.data.repo === '' ? '@' : repoInitials(form.data.repo)}
                                </span>
                                {form.data.repo || (noRepos ? 'No repository' : 'Pick a repo')} <ChevronDown size={12} className="text-faint" aria-hidden="true" />
                            </span>
                        }
                        items={repos.map((repo) => ({
                            key: repo.slug,
                            label: repo.slug,
                            checked: repo.slug === form.data.repo,
                            onSelect: () => {
                                form.setData((data) => ({ ...data, repo: repo.slug, repositories: data.repositories.filter((extra) => extra.repo !== repo.slug) }));
                            },
                        }))}
                    />
                    <div className="inline-flex h-7 gap-0.5 rounded-control bg-panel-2 p-0.5" role="group" aria-label="Mode">
                        {MODES.map(({ mode, label, icon: Icon, hint }) => (
                            <button
                                key={mode}
                                type="button"
                                aria-pressed={form.data.mode === mode}
                                title={hint}
                                onClick={() => form.setData('mode', mode)}
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-[5px] px-2.5 text-[12.5px] font-medium transition-colors',
                                    form.data.mode === mode ? 'bg-accent-soft text-body' : 'text-muted hover:text-body',
                                )}
                                data-testid={`start-mode-${mode}`}
                            >
                                <Icon size={13} aria-hidden="true" />
                                {label}
                            </button>
                        ))}
                    </div>
                    <AttachButton draft={draft} disabled={form.processing} />
                    <div className="ml-auto flex items-center gap-2.5">
                        <Kbd keys={['⌘', '↵']} className="hidden pointer-fine:inline-flex" />
                        <Button variant="primary" className="h-7" pending={form.processing || startedThreadId !== null} disabled={!canSubmit} onClick={submit} data-testid="start-thread-submit">
                            {form.processing || startedThreadId !== null ? 'Starting...' : `Start ${isResearch ? 'research' : 'change'}`}
                        </Button>
                    </div>
                </div>
                <RunsOnStrip
                    picker={picker}
                    accountId={form.data.claude_account_id}
                    onAccountChange={(id) => form.setData('claude_account_id', id)}
                    model={effectiveModel}
                    onModelChange={(model) => form.setData('model', model)}
                    disabled={noAccounts || form.processing}
                    accountsHelpUrl={`${docs.baseUrl.replace(/\/$/, '')}/claude-accounts/`}
                    defaultLabel={defaultAccount ? `${defaultAccount.name} (default)` : 'Default account'}
                    defaultAccount={defaultAccount}
                />
                <StartHints isResearch={isResearch} />
            </div>

            {startedThreadId !== null ? (
                <div className="mt-3">
                    <RepositoryProgress threadId={startedThreadId} onReady={openThread} />
                </div>
            ) : (
                !noRepos && (
                    <div className="mt-3 flex flex-col gap-2">
                        <RepositoryPicker
                            primary={form.data.repo}
                            extras={extras}
                            available={repos.map((repo) => repo.slug)}
                            isResearch={isResearch}
                            disabled={form.processing || form.data.repo === ''}
                            onChange={(next) => form.setData('repositories', next)}
                        />
                        {extras.length > 0 && (
                            <div className="flex items-start gap-3 text-[12px] text-muted">
                                <p data-testid="repo-note">
                                    {contextRepos.length > 0 && <>{contextRepos.map((extra) => extra.repo).join(', ')} {contextRepos.length > 1 ? 'are' : 'is'} throwaway, with no pull request. </>}
                                    Yak starts from {form.data.repo}'s template, then clones the others and runs their setup, so the first start takes longer.
                                </p>
                                {!isResearch && (
                                    <span className="ml-auto shrink-0" data-testid="expected-pr-count">
                                        {expectedPullRequests} pull {expectedPullRequests === 1 ? 'request' : 'requests'} expected
                                    </span>
                                )}
                            </div>
                        )}
                    </div>
                )
            )}

            {noAccounts && (
                <p className="mt-3 text-[12.5px] text-fail" role="alert" data-testid="start-thread-no-accounts">
                    No usable Claude account. <Link href={claudeAccounts.url()} className="underline">Connect one in Settings</Link>, or ask an admin to set up a shared account.
                </p>
            )}
            {noRepos && (
                <p className="mt-3 text-[12.5px] text-muted" data-testid="start-thread-no-repos">
                    No repository is set up yet. Add one under Repositories first.
                </p>
            )}
            {error && (
                <p className="mt-3 text-[12.5px] text-fail" role="alert" data-testid="start-thread-error">
                    {error}
                </p>
            )}
        </div>
    );
}
