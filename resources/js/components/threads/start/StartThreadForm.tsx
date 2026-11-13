import { router, useForm, usePage } from '@inertiajs/react';
import { cn } from '@geocodio/console-ui';
import { ArrowUp, ExternalLink, Info, Loader2, Search, Wrench } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { YakMarkFace } from '@/components/brand/YakMark';
import { useStartField, type StartExample } from '@/components/start/StartFieldContext';
import { AttachButton, AttachmentDropOverlay, DraftAttachments } from '@/components/attachments/Attachments';
import { AttachmentTextarea, type AttachmentTextareaHandle } from '@/components/attachments/AttachmentTextarea';
import { useAttachmentDraft } from '@/components/attachments/useAttachmentDraft';
import { RunsOnStrip } from './RunsOnStrip';
import { StartHints } from './StartHints';
import { StartErrorBanner } from './StartErrorBanner';
import { BrokenDefaultNote, NoOwnAccountNote, resolveAccountNote } from './accountNotes';
import { RepositoryPicker, type PickerRepository } from './RepositoryPicker';
import { RepositoryProgress } from './RepositoryProgress';
import { CONTOUR } from '@/lib/contour';
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
export function StartThreadForm({
    repos,
    picker,
    defaultRepo,
    onStarted,
    autoFocus,
    onRepoChange,
    isQueueing = false,
    isRepoBlocked = false,
    example,
}: Omit<NewThreadPageData, 'emailToYakAddress'> & {
    onStarted?: () => void;
    autoFocus?: boolean;
    /** Called with the selected repository, so a wrapper can show that repository's setup state. */
    onRepoChange?: (slug: string) => void;
    /** The selected repository is still being set up: the thread is created now and starts when setup is done. */
    isQueueing?: boolean;
    /** The selected repository cannot take a thread, so Start stays disabled. */
    isRepoBlocked?: boolean;
    /** A request an example chip dropped in: it replaces the description and mode, and the composer takes focus. */
    example?: StartExample;
}) {
    const { docs } = usePage<SharedProps>().props;
    const field = useStartField();
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
    useEffect(() => onRepoChange?.(form.data.repo), [form.data.repo, onRepoChange]);
    const editorRef = useRef<AttachmentTextareaHandle>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const lastLength = useRef(0);
    const draft = useAttachmentDraft({ enabled: true, onAdd: (added) => editorRef.current?.insertTokens(added) });
    const errors = form.errors as Record<string, string | undefined>;

    const noAccounts = picker.accounts.every((account) => account.usable === false);
    const noRepos = repos.length === 0;
    const defaults = picker.defaultsByRepo[form.data.repo];
    const accountNote = resolveAccountNote(picker.accounts, defaults?.brokenDefaultAccountId ?? null);
    const defaultAccount = picker.accounts.find((account) => account.id === defaults?.accountId);
    const chosenAccount = picker.accounts.find((account) => String(account.id) === form.data.claude_account_id);
    const effectiveModel = form.data.model ?? chosenAccount?.defaultModel ?? defaults?.model ?? null;
    const canSubmit = !noAccounts && !noRepos && form.data.repo !== '' && form.data.description.trim().length >= 3 && !isBlocked && !isRepoBlocked;

    const extras = form.data.repositories;
    const isResearch = form.data.mode === 'research';
    const contextRepos = extras.filter((extra) => isResearch || extra.role === 'context');
    const expectedPullRequests = 1 + extras.filter((extra) => extra.role === 'changes').length;
    const isStarting = form.processing || startedThreadId !== null;
    // The start screen lets the eruption finish before it leaves the page.
    const openThread = useCallback(() => {
        if (startedThreadId !== null) {
            window.setTimeout(() => router.visit(show.url(startedThreadId)), field?.settleMs() ?? 0);
        }
    }, [startedThreadId, field]);

    useEffect(() => {
        if (example === undefined) {
            return;
        }
        form.setData((data) => ({ ...data, description: example.text, mode: example.mode }));
        lastLength.current = example.text.length;
        field?.wave();
        requestAnimationFrame(() => {
            textareaRef.current?.focus();
            textareaRef.current?.setSelectionRange(example.text.length, example.text.length);
        });
        // Only a new example id should replace the description.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [example?.id]);

    const changeDescription = (value: string) => {
        form.setData('description', value);
        if (value.length > lastLength.current && value.length % CONTOUR.typeEvery === 0) {
            field?.wave();
        }
        lastLength.current = value.length;
    };

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
                field?.erupt();
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

    const startLabel = `${isQueueing ? 'Queue' : 'Start'} ${isResearch ? 'research' : 'change'}`;
    const isStartDisabled = !canSubmit || isStarting;

    return (
        <div data-testid="start-thread-form">
            <div
                className="relative overflow-hidden rounded-2xl border border-hair bg-panel shadow-[var(--composer-shadow)] transition-[border-color,box-shadow] duration-200 focus-within:border-[var(--accent-edge)] focus-within:shadow-[var(--composer-shadow),0_0_0_3px_var(--composer-focus-ring)] motion-reduce:transition-none"
                {...draft.dropzoneProps}
            >
                <AttachmentDropOverlay draft={draft} />
                <div className="flex items-start gap-3 px-4 pb-1 pt-4">
                    <YakMarkFace className="-mt-px size-[30px] shrink-0" />
                    <AttachmentTextarea
                        ref={editorRef}
                        inputRef={textareaRef}
                        draft={draft}
                        rows={3}
                        autoFocus={autoFocus}
                        placeholder="What should Yak do? Say what is wrong or what you want, and where to look if you know."
                        value={form.data.description}
                        onValueChange={changeDescription}
                        onFocus={() => field?.wave()}
                        onKeyDown={onKeyDown}
                        onPaste={draft.onPaste}
                        aria-invalid={errors.description ? true : undefined}
                        wrapperClassName="min-w-0 flex-1"
                        className="min-h-[78px] w-full resize-none rounded-none border-0 bg-transparent px-0 py-[3px] text-[16px] leading-[1.5] tracking-[-0.006em] shadow-none outline-none caret-accent-text hover:border-0 focus:shadow-none focus:ring-0"
                        data-testid="start-thread-description"
                    />
                </div>
                <DraftAttachments draft={draft} errors={errors} progress={form.processing && draft.items.length > 0 ? (form.progress?.percentage ?? 0) : null} onOpenMedia={() => {}} />
                <div className="flex flex-wrap items-center gap-2 px-3 pb-3 pt-2">
                    <div className="inline-flex gap-0.5 rounded-[10px] border border-hair bg-panel-2/60 p-0.5" role="group" aria-label="Mode">
                        {MODES.map(({ mode, label, icon: Icon }) => {
                            const isActive = form.data.mode === mode;

                            return (
                                <button
                                    key={mode}
                                    type="button"
                                    aria-pressed={isActive}
                                    onClick={() => form.setData('mode', mode)}
                                    className={cn(
                                        'inline-flex h-[30px] items-center gap-1.5 rounded-lg pl-[9px] pr-[11px] text-[13px] font-medium transition-[color,background-color,transform] duration-150 active:scale-[0.97] motion-reduce:transition-none',
                                        isActive ? 'bg-panel text-body shadow-[0_1px_2px_rgba(0,0,0,0.1),0_0_0_1px_var(--border)]' : 'text-muted hover:text-body',
                                    )}
                                    data-testid={`start-mode-${mode}`}
                                >
                                    <Icon size={14} className={isActive ? 'text-accent-text' : undefined} aria-hidden="true" />
                                    {label}
                                </button>
                            );
                        })}
                    </div>
                    {isBlocked && (
                        <span className="order-last basis-full text-[12px] text-fail sm:order-none sm:basis-auto" data-testid="start-thread-blocked">
                            Remove {extras.find((extra) => extra.blocked !== undefined)?.repo} or fix its access to start.
                        </span>
                    )}
                    <div className="ml-auto flex items-center gap-2">
                        <AttachButton draft={draft} disabled={form.processing} size={17} className="size-[34px] rounded-[9px] text-muted hover:text-body active:scale-[0.94]" />
                        <span className="hidden gap-[3px] sm:pointer-fine:inline-flex" aria-hidden="true">
                            {['⌘', '↵'].map((key) => (
                                <kbd key={key} className="inline-grid h-5 min-w-5 place-items-center rounded-md border border-b-2 border-hair bg-app px-[5px] font-sans text-[11px] font-medium leading-none text-muted">
                                    {key}
                                </kbd>
                            ))}
                        </span>
                        <button
                            type="button"
                            onClick={submit}
                            disabled={isStartDisabled}
                            aria-label={isStarting ? 'Starting' : startLabel}
                            aria-busy={isStarting || undefined}
                            className={cn(
                                'inline-flex h-[34px] items-center gap-1.5 whitespace-nowrap rounded-[10px] pl-[11px] pr-[13px] text-[13px] font-semibold tracking-[-0.005em] transition-[transform,filter,background-color] duration-150 motion-reduce:transition-none',
                                isStartDisabled && !isStarting
                                    ? 'cursor-not-allowed bg-hair text-faint'
                                    : 'bg-accent text-accent-ink shadow-[inset_0_1px_0_rgba(255,255,255,0.18),0_1px_2px_rgba(0,0,0,0.16)] hover:brightness-[1.07] active:translate-y-px active:scale-[0.98] active:brightness-95 disabled:cursor-progress',
                            )}
                            data-testid="start-thread-submit"
                        >
                            {isStarting ? <Loader2 size={15} className="animate-spin" aria-hidden="true" /> : <ArrowUp size={15} aria-hidden="true" />}
                            <span className="max-sm:hidden">{isStarting ? 'Starting...' : startLabel}</span>
                            <span className="sm:hidden">{isStarting ? 'Starting' : isQueueing ? 'Queue' : 'Start'}</span>
                        </button>
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
                    note={
                        accountNote.kind === 'own-missing' ? (
                            <NoOwnAccountNote sharedName={accountNote.sharedName ?? ''} accountsUrl={claudeAccounts.url()} />
                        ) : accountNote.kind === 'repo-default-broken' ? (
                            <BrokenDefaultNote brokenName={accountNote.brokenName ?? ''} fallbackName={accountNote.fallbackName ?? ''} accountsUrl={claudeAccounts.url()} />
                        ) : undefined
                    }
                />
                {startedThreadId !== null ? (
                    <div className="border-t border-hair px-3 py-2">
                        <RepositoryProgress threadId={startedThreadId} onReady={openThread} />
                    </div>
                ) : (
                    !noRepos && (
                        <div className="border-t border-hair px-3 py-2">
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
                        </div>
                    )
                )}
                {error && <StartErrorBanner message={error} />}
                <StartHints isResearch={isResearch} />
            </div>

            {startedThreadId === null && !noRepos && extras.length > 0 && (
                <p className="mt-3 flex items-start gap-1.5 text-[12px] text-muted" data-testid="repo-note">
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

            {!isResearch && extras.length > 0 && (
                <p className="mt-2 text-right text-[12px] text-muted" data-testid="expected-pr-count">
                    {expectedPullRequests} pull {expectedPullRequests === 1 ? 'request' : 'requests'} expected
                </p>
            )}
        </div>
    );
}
