import { Link, useForm, usePage } from '@inertiajs/react';
import { Button, Menu, cn } from '@geocodio/console-ui';
import { ChevronDown, Search, Wrench } from 'lucide-react';
import { useRef, type KeyboardEvent } from 'react';
import { AccountPicker } from '@/components/accounts/AccountPicker';
import { AttachButton, AttachmentDropOverlay, DraftAttachments } from '@/components/attachments/Attachments';
import { AttachmentTextarea, type AttachmentTextareaHandle } from '@/components/attachments/AttachmentTextarea';
import { useAttachmentDraft } from '@/components/attachments/useAttachmentDraft';
import { ModelPicker } from '@/components/threads/composer/ModelPicker';
import { store } from '@/routes/threads';
import { claudeAccounts } from '@/routes/settings';
import type { SharedProps } from '@/types/shared';
import type { NewThreadPageData } from '@/types/threads';

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
export function StartThreadForm({ repos, picker, defaultRepo, onStarted, autoFocus }: NewThreadPageData & { onStarted?: () => void; autoFocus?: boolean }) {
    const { docs } = usePage<SharedProps>().props;
    const form = useForm<{ repo: string; mode: Mode; description: string; claude_account_id: string; model: string | null; attachments: File[]; attachment_refs: string[] }>({
        repo: defaultRepo ?? '',
        mode: 'fix',
        description: '',
        claude_account_id: '',
        model: null,
        attachments: [],
        attachment_refs: [],
    });
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
        form.post(store.url(), { onSuccess: () => onStarted?.() });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            event.preventDefault();
            submit();
        }
    };

    const error = errors.claude_account_id ?? errors.repo ?? errors.description ?? errors.model ?? errors.mode;

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
                    <Menu
                        aria-label="Repository"
                        disabled={noRepos}
                        trigger={
                            <span className="flex items-center gap-1 text-[12.5px] font-medium text-body" data-testid="start-thread-repo">
                                {form.data.repo || (noRepos ? 'No repository' : 'Pick a repo')} <ChevronDown size={12} className="text-faint" aria-hidden="true" />
                            </span>
                        }
                        items={repos.map((repo) => ({
                            key: repo.slug,
                            label: repo.slug,
                            checked: repo.slug === form.data.repo,
                            onSelect: () => form.setData('repo', repo.slug),
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
                    <Button variant="primary" className="ml-auto h-7" pending={form.processing} disabled={!canSubmit} onClick={submit} data-testid="start-thread-submit">
                        Start thread
                    </Button>
                </div>
            </div>

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
