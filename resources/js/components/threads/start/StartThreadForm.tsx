import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Button, Menu, cn } from '@geocodio/console-ui';
import { CircleHelp, ExternalLink, Info, Search, Wrench } from 'lucide-react';
import { useCallback, useRef, useState, type KeyboardEvent } from 'react';
import { AccountPicker } from '@/components/accounts/AccountPicker';
import { AttachButton, AttachmentDropOverlay, DraftAttachments } from '@/components/attachments/Attachments';
import { AttachmentTextarea, type AttachmentTextareaHandle } from '@/components/attachments/AttachmentTextarea';
import { useAttachmentDraft } from '@/components/attachments/useAttachmentDraft';
import { ModelPicker } from '@/components/threads/composer/ModelPicker';
import { RepositoryPicker, type PickerRepository } from './RepositoryPicker';
import { RepositoryProgress } from './RepositoryProgress';
import { show, store } from '@/routes/threads';
import { claudeAccounts } from '@/routes/settings';
import type { SharedProps } from '@/types/shared';
import type { NewThreadPageData } from '@/types/threads';

type Mode = 'fix' | 'research';

const MODES: { mode: Mode; label: string; icon: typeof Wrench; hint: string }[] = [
    { mode: 'fix', label: 'Change', icon: Wrench, hint: 'Yak makes the change and opens a pull request for you to review. With several repos, one pull request per repo that changed.' },
    { mode: 'research', label: 'Research', icon: Search, hint: 'Yak investigates and writes a report. No pull request.' },
];

/**
 * The start-a-thread form shared by the Threads/New page and the dialog:
 * description, repo, mode, attachments, and the "Runs on / with" account and
 * model pickers. Changing the repo re-resolves the defaults from
 * `picker.defaultsByRepo`.
 */
export function StartThreadForm({ repos, picker, defaultRepo, onStarted, autoFocus }: Omit<NewThreadPageData, 'emailToYakAddress'> & { onStarted?: () => void; autoFocus?: boolean }) {
    const { docs } = usePage<SharedProps>().props;
    const form = useForm<{ repo: string; mode: Mode; description: string; claude_account_id: string; model: string | null; attachments: File[]; attachment_refs: string[]; repositories: PickerRepository[] }>({
        repo: defaultRepo ?? '',
        mode: 'fix',
        description: '',
        claude_account_id: '',
        model: null,
        attachments: [],
        attachment_refs: [],
        repositories: [],
    });
    const [isBlocked, setIsBlocked] = useState(false);
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
    const canSubmit = !noAccounts && !noRepos && form.data.repo !== '' && form.data.description.trim().length >= 3 && !isBlocked;

    const extras = form.data.repositories;
    const isResearch = form.data.mode === 'research';
    const contextRepos = extras.filter((extra) => isResearch || extra.role === 'context');
    const expectedPullRequests = 1 + extras.filter((extra) => extra.role === 'changes').length;
    const isStarting = form.processing || startedThreadId !== null;
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
            repositories: data.repositories.map(({ repo, role }) => ({ repo, role })),
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
            <div className="relative rounded-card border border-hair bg-panel shadow-card focus-within:border-accent" {...draft.dropzoneProps}>
                <AttachmentDropOverlay draft={draft} />
                <AttachmentTextarea
                    ref={editorRef}
                    draft={draft}
                    rows={5}
                    autoFocus={autoFocus}
                    placeholder="Describe the change or the question. Paste or drop screenshots and files."
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
                    <div className="inline-flex h-7 gap-0.5 rounded-control bg-panel-2 p-0.5" role="group" aria-label="Mode">
                        {MODES.map(({ mode, label, icon: Icon }) => (
                            <button
                                key={mode}
                                type="button"
                                aria-pressed={form.data.mode === mode}
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
                    {isBlocked && (
                        <span className="ml-auto text-[12px] text-fail" data-testid="start-thread-blocked">
                            Remove {extras.find((extra) => extra.blocked !== undefined)?.repo} or fix its access to start.
                        </span>
                    )}
                    <Button variant="primary" className={cn('h-7', !isBlocked && 'ml-auto')} pending={isStarting} disabled={!canSubmit} onClick={submit} data-testid="start-thread-submit">
                        {isStarting ? 'Starting...' : isResearch ? 'Start research' : 'Start change'}
                    </Button>
                </div>
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
                            onBlockedChange={setIsBlocked}
                            docsUrl={docs.baseUrl}
                            onPrimaryChange={(slug) => form.setData((data) => ({ ...data, repo: slug, repositories: data.repositories.filter((extra) => extra.repo !== slug) }))}
                            isResearch={isResearch}
                            disabled={form.processing || form.data.repo === ''}
                            onChange={(next) => form.setData('repositories', next)}
                        />
                        {extras.length > 0 && (
                            <p className="flex items-start gap-1.5 text-[12px] text-muted" data-testid="repo-note">
                                <Info size={13} className="mt-0.5 shrink-0" aria-hidden="true" />
                                <span>
                                    {contextRepos.length > 0 && <>{contextRepos.map((extra) => extra.repo).join(', ')} {contextRepos.length > 1 ? 'are' : 'is'} throwaway, with no pull request. </>}
                                    Yak starts from {form.data.repo}'s template, then clones the others and runs their setup, so the first start takes longer.{' '}
                                    <a href={docs.baseUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-0.5 underline">
                                        How multi-repo threads work <ExternalLink size={11} aria-hidden="true" />
                                    </a>
                                </span>
                            </p>
                        )}
                    </div>
                )
            )}

            <div className="mt-3 flex flex-wrap items-center gap-2 text-[12px] text-muted" data-testid="start-thread-runs-on">
                <span>Runs on</span>
                <AccountPicker
                    label="Claude account"
                    value={form.data.claude_account_id}
                    accounts={picker.accounts}
                    onChange={(id) => form.setData('claude_account_id', id)}
                    disabled={noAccounts || form.processing}
                    defaultLabel={defaultAccount ? `${defaultAccount.name} (default)` : 'Default account'}
                    defaultAccount={defaultAccount}
                    className="h-7 min-w-[160px]"
                />
                <span>with</span>
                <ModelPicker models={picker.models} value={effectiveModel} onChange={(model) => form.setData('model', model)} disabled={noAccounts || form.processing} />
                <span title="Which Claude account and model run this thread" aria-label="Which Claude account and model run this thread" className="inline-flex text-faint">
                    <CircleHelp size={13} aria-hidden="true" />
                </span>
                <a href={docs.baseUrl} target="_blank" rel="noreferrer" className="ml-auto underline">
                    How accounts work
                </a>
            </div>

            <div className="mt-2 flex items-center gap-3 text-[12px] text-muted" data-testid="start-thread-footer">
                <p>{MODES.find((entry) => entry.mode === form.data.mode)?.hint}</p>
                {!isResearch && extras.length > 0 && (
                    <span className="ml-auto shrink-0" data-testid="expected-pr-count">
                        {expectedPullRequests} pull {expectedPullRequests === 1 ? 'request' : 'requests'} expected
                    </span>
                )}
            </div>

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

            <p className="mt-4 text-[12px] text-muted">
                <a href={docs.baseUrl} target="_blank" rel="noreferrer" className="underline">
                    How threads work
                </a>
            </p>
        </div>
    );
}
