import { useForm, usePage } from '@inertiajs/react';
import { Button, Checkbox, Dialog, Field, Kbd, Select, TextInput, toast } from '@geocodio/console-ui';
import { AlertTriangle, Check, ChevronRight, Copy } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { rotate, store } from '@/routes/settings/claude-accounts';
import { useDocsUrl } from '@/lib/docsUrl';
import type { ClaudeAccount } from '@/types/claudeAccounts';
import type { SharedProps } from '@/types/shared';
import { modelOptions } from './accountAppearance';
import { AppearanceFields } from './AppearanceFields';
import { ConnectedState, type ConnectedAccount } from './ConnectedState';
import { cleanSecret, detectKind } from './secretKind';

export type AccountDialogMode = 'add' | 'addSystem' | 'rotate';

const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

function CommandBox() {
    const [copied, setCopied] = useState(false);

    const copy = () => {
        void navigator.clipboard?.writeText('claude setup-token');
        setCopied(true);
        toast.success('Copied');
        setTimeout(() => setCopied(false), 1600);
    };

    return (
        <div className="flex items-center justify-between gap-2 rounded-control border border-hair bg-panel-2 py-1.5 pl-3 pr-1.5">
            <code className="font-mono text-[12.5px]"><span className="select-none text-faint">$ </span>claude setup-token</code>
            <Button variant="secondary" icon={copied ? <Check size={13} /> : <Copy size={13} />} onClick={copy}>{copied ? 'Copied' : 'Copy'}</Button>
        </div>
    );
}

function Step({ n, children }: { n: number; children: ReactNode }) {
    return (
        <li className="flex gap-2.5">
            <span className="mt-px grid size-5 shrink-0 place-items-center rounded-pill bg-panel-2 text-[11px] font-medium text-muted tnum">{n}</span>
            <div className="min-w-0 flex-1">{children}</div>
        </li>
    );
}

function DocsLink({ href, children }: { href: string; children: ReactNode }) {
    return <a href={href} target="_blank" rel="noreferrer" className={`rounded-[3px] text-accent-text hover:underline ${FOCUS}`}>{children}</a>;
}

type Props = {
    mode: AccountDialogMode;
    account?: Pick<ClaudeAccount, 'id' | 'name'>;
    models: string[];
    hasDefault: boolean;
    onClose: () => void;
    /** When set, the connected state offers Teleport setup to people without a paired computer. */
    onSetUpTeleport?: () => void;
};

function defaultName(isSystem: boolean, appName: string | undefined): string {
    if (!isSystem) {
        return 'Personal';
    }

    const name = appName ?? 'Yak';

    return name.toLowerCase().startsWith('yak') ? name : `Yak ${name}`;
}

/** Add (a new account) and rotate (a new secret for an existing one) share the paste, check and save flow. */
export function AccountDialog({ mode, account, models, hasDefault, onClose, onSetUpTeleport }: Props) {
    const isRotate = mode === 'rotate';
    const isSystem = mode === 'addSystem';
    const setup = usePage<SharedProps>().props.setup;
    const setupDocsUrl = useDocsUrl('setup/');
    const form = useForm({
        secret: '',
        name: isRotate ? '' : defaultName(isSystem, setup?.appName),
        default_model: 'sonnet' as string | null,
        make_default: !hasDefault,
        icon: (isSystem ? 'building' : 'briefcase') as ClaudeAccount['icon'],
        color: (isSystem ? 'slate' : 'violet') as ClaudeAccount['color'],
        is_system: isSystem,
    });
    const [isTouched, setIsTouched] = useState(false);
    const [isDetailsOpen, setIsDetailsOpen] = useState(false);
    const [connected, setConnected] = useState<ConnectedAccount | null>(null);
    const detectedKind = detectKind(form.data.secret);
    const formatProblem = form.data.secret !== '' && detectedKind === null
        ? 'This does not look like a token or key. It starts with sk-ant-. Paste the whole line that claude setup-token printed.'
        : null;
    const secretError = form.errors.secret ?? (formatProblem !== null && (isTouched || form.data.secret.length > 24) ? formatProblem : undefined);
    const canSave = detectedKind !== null && (isRotate || form.data.name.trim() !== '');

    const save = () => {
        if (!canSave || form.processing) {
            return;
        }

        if (isRotate && account) {
            form.transform((data) => ({ secret: data.secret }));
            form.post(rotate.url(account.id), { preserveScroll: true, onSuccess: () => { form.reset('secret'); onClose(); } });
        } else {
            form.transform((data) => ({ ...data, make_default: isSystem ? undefined : data.make_default }));
            form.post(store.url(), {
                preserveScroll: true,
                onSuccess: (page) => {
                    form.reset('secret');
                    const flashed = (page.props as unknown as SharedProps).flash.connectedAccount;

                    if (flashed) {
                        setConnected(flashed);
                    } else {
                        onClose();
                    }
                },
            });
        }
    };

    if (connected) {
        return (
            <Dialog
                open
                onOpenChange={(open) => !open && onClose()}
                title={isSystem ? 'Yak account connected' : 'Claude account connected'}
                hideTitle
                width="w-[min(560px,100vw)]"
                footer={<div className="flex justify-end"><Button variant="secondary" onClick={onClose} data-testid="account-connected-done">Done</Button></div>}
            >
                <ConnectedState account={connected} isSystem={isSystem} hasDevice={setup?.hasDevice ?? false} onSetUpTeleport={onSetUpTeleport ? () => { onClose(); onSetUpTeleport(); } : undefined} />
            </Dialog>
        );
    }

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
            title={isRotate ? `Replace the secret for ${account?.name}` : isSystem ? 'Add the shared Yak account' : 'Connect your Claude account'}
            description={
                isRotate ? 'Paste a new secret. Yak checks it with a test message before it replaces the old one, and the old one stays until the check passes.'
                    : isSystem ? 'Yak uses it for work nobody started: Sentry alerts, Linear automation and flaky tests. People without their own account also run on it.'
                        : 'Your tasks then run on your own Claude subscription, not on the shared Yak account. It takes about a minute.'
            }
            width="w-[min(560px,100vw)]"
            onPopupKeyDown={(event: React.KeyboardEvent) => {
                if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                    event.preventDefault();
                    save();
                }
            }}
            footer={
                <div className="flex items-center justify-end gap-2">
                    <p className="mr-auto hidden max-w-[240px] text-[12px] leading-snug text-muted sm:block">Yak sends one test message, then stores the token encrypted. It is never shown again.</p>
                    <Kbd keys={['⌘', '↵']} className="mr-1 hidden sm:pointer-fine:inline-flex" />
                    <Button variant="tertiary" disabled={form.processing} onClick={onClose} data-testid="account-cancel">Cancel</Button>
                    <Button variant="primary" disabled={!canSave} pending={form.processing} pendingLabel="Checking with Claude..." onClick={save} data-testid="account-save">
                        {isRotate ? 'Check and replace' : 'Connect'}
                    </Button>
                </div>
            }
        >
            <div className="flex flex-col gap-5">
                {isSystem && (
                    <div className="flex gap-2.5 rounded-card bg-warn-soft px-3.5 py-3 text-[12.5px] leading-relaxed" data-testid="account-system-note">
                        <span className="mt-1.5 size-2 shrink-0 rounded-pill bg-warn" aria-hidden="true" />
                        <span>Use a Claude seat that belongs to the team, not your personal one. Everyone&apos;s unattended work spends its limits.</span>
                    </div>
                )}

                <ol className="flex flex-col gap-4 text-[13px]">
                    <Step n={1}>
                        <div className="flex flex-col gap-2">
                            <p className="leading-relaxed">
                                {isSystem
                                    ? 'Run this on your own computer. It only prints a token. Your own Claude login on this computer stays as it is.'
                                    : 'Run this in a terminal on your computer. A browser window opens. Sign in and approve.'}
                            </p>
                            <CommandBox />
                            <p className="text-[12px] leading-relaxed text-muted">
                                {isSystem
                                    ? <>A sign-in page opens. Copy its address into a private browser window, and sign in to the team seat there. <DocsLink href={setupDocsUrl}>Or run it on the Yak server over SSH</DocsLink></>
                                    : <>No Claude Code on this computer? <DocsLink href="https://code.claude.com/docs/en/setup">Install it first</DocsLink></>}
                            </p>
                        </div>
                    </Step>
                    <Step n={2}>
                        <div className="flex flex-col gap-2">
                            <Field label="Paste the token it prints." error={secretError}>
                                <TextInput
                                    className="font-mono text-[13px]"
                                    spellCheck={false}
                                    autoComplete="off"
                                    disabled={form.processing}
                                    value={form.data.secret}
                                    onBlur={() => setIsTouched(true)}
                                    onChange={(event) => { form.setData('secret', cleanSecret(event.target.value)); form.clearErrors('secret'); }}
                                    placeholder="sk-ant-oat01-..."
                                    data-testid="account-secret"
                                />
                            </Field>
                            {detectedKind !== null && (
                                <p className="flex items-center gap-1.5 text-[12px] font-medium text-ok" data-testid="account-detected-kind" role="status">
                                    <Check size={13} aria-hidden="true" />
                                    {detectedKind === 'setup_token' ? 'Setup token. It works for one year, and Yak reminds you before it expires.' : 'API key. Anthropic bills each task as it runs.'}
                                </p>
                            )}
                            {detectedKind === 'api_key' && <p className="flex items-start gap-1.5 text-[12px] text-muted"><AlertTriangle size={13} className="mt-0.5 shrink-0 text-warn" aria-hidden="true" />Yak sets no spending cap on API keys.</p>}
                            {form.processing && <p className="text-[12px] text-muted" role="status">Sending a one-word test message on Haiku...</p>}
                            <p className="text-[12px] leading-relaxed text-muted">Line breaks from your terminal are removed for you. An API key from the Anthropic Console also works in this box.</p>
                        </div>
                    </Step>
                </ol>

                {!isRotate && (
                    <div className="flex flex-col gap-2 pl-[30px]">
                        <Field label="Name" description={isSystem ? 'Everyone sees this name wherever a task runs on it.' : undefined} error={form.errors.name}>
                            <TextInput disabled={form.processing} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} data-testid="account-name" />
                        </Field>
                        <button
                            type="button"
                            aria-expanded={isDetailsOpen}
                            aria-controls="account-details"
                            onClick={() => setIsDetailsOpen((open) => !open)}
                            className={`inline-flex min-h-7 items-center gap-1 self-start rounded-[3px] text-[12.5px] text-accent-text hover:underline ${FOCUS}`}
                            data-testid="account-details-toggle"
                        >
                            <ChevronRight size={12} className={isDetailsOpen ? 'rotate-90' : ''} aria-hidden="true" />Model, icon and colour
                        </button>
                        {isDetailsOpen && (
                            <div id="account-details" className="flex flex-col gap-4" data-testid="account-details">
                                <Field label="Default model" description="Used when a task does not pick one.">
                                    <Select disabled={form.processing} value={form.data.default_model} onChange={(value) => form.setData('default_model', value)} options={modelOptions(models)} />
                                </Field>
                                <AppearanceFields value={{ icon: form.data.icon, color: form.data.color }} onChange={(next) => form.setData((data) => ({ ...data, ...next }))} />
                                {!isSystem && <Checkbox checked={form.data.make_default} onCheckedChange={(checked) => form.setData('make_default', checked)} label="Make this my default account" />}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </Dialog>
    );
}
