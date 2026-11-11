import { useForm } from '@inertiajs/react';
import { Button, Checkbox, Dialog, Field, Kbd, RadioGroup, Select, Textarea, TextInput, toast } from '@geocodio/console-ui';
import { AlertTriangle, Check, Copy } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { rotate, store } from '@/routes/settings/claude-accounts';
import type { AccountKind, ClaudeAccount } from '@/types/claudeAccounts';
import { modelOptions, TOKEN_PREFIX } from './accountAppearance';
import { AppearanceFields } from './AppearanceFields';

export type AccountDialogMode = 'add' | 'addSystem' | 'rotate';

const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/** Mirrors the server's format messages so the person sees them before the check round trip. */
export function secretProblem(kind: AccountKind, rawSecret: string): string | null {
    const secret = rawSecret.trim();

    if (secret === '') {
        return null;
    }

    if (!secret.startsWith(TOKEN_PREFIX[kind])) {
        return kind === 'setup_token'
            ? 'This does not look like a setup token. It starts with sk-ant-oat01-. Paste the whole line that claude setup-token printed.'
            : 'This does not look like an API key. It starts with sk-ant-api03-. Copy the whole key from the Anthropic Console.';
    }

    return /\s/.test(secret) ? 'The secret has a space or line break in it. Paste it again without anything around it.' : null;
}

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
    account?: ClaudeAccount;
    models: string[];
    hasDefault: boolean;
    onClose: () => void;
};

/** Add (a new account) and rotate (a new secret for an existing one) share the paste, check and save flow. */
export function AccountDialog({ mode, account, models, hasDefault, onClose }: Props) {
    const isRotate = mode === 'rotate';
    const isSystem = mode === 'addSystem';
    const form = useForm({
        kind: (account?.kind ?? 'setup_token') as AccountKind,
        secret: '',
        name: '',
        default_model: 'sonnet' as string | null,
        make_default: !hasDefault,
        icon: (isSystem ? 'building' : 'briefcase') as ClaudeAccount['icon'],
        color: (isSystem ? 'slate' : 'violet') as ClaudeAccount['color'],
        is_system: isSystem,
    });
    const [isTouched, setIsTouched] = useState(false);
    const isToken = form.data.kind === 'setup_token';
    const formatProblem = secretProblem(form.data.kind, form.data.secret);
    const secretError = form.errors.secret ?? (formatProblem !== null && (isTouched || form.data.secret.length > 24) ? formatProblem : undefined);
    const canSave = form.data.secret.trim() !== '' && (isRotate || form.data.name.trim() !== '') && formatProblem === null;

    const save = () => {
        if (!canSave || form.processing) {
            return;
        }

        const options = { preserveScroll: true, onSuccess: () => { form.reset('secret'); onClose(); } };

        if (isRotate && account) {
            form.transform((data) => ({ secret: data.secret }));
            form.post(rotate.url(account.id), options);
        } else {
            form.transform((data) => ({ ...data, make_default: isSystem ? undefined : data.make_default }));
            form.post(store.url(), options);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
            title={isRotate ? `Replace the secret for ${account?.name}` : isSystem ? 'Add a Yak system account' : 'Add a Claude account'}
            description={isRotate ? 'Paste a new secret. Yak checks it with a test message before it replaces the old one, and the old one stays until the check passes.' : 'Yak checks the secret with a short test message before saving it. After that it is stored encrypted and never shown again.'}
            width="w-[min(540px,100vw)]"
            onPopupKeyDown={(event: React.KeyboardEvent) => {
                if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                    event.preventDefault();
                    save();
                }
            }}
            footer={
                <div className="flex items-center justify-end gap-2">
                    <Kbd keys={['⌘', '↵']} className="mr-1 hidden sm:pointer-fine:inline-flex" />
                    <Button variant="tertiary" disabled={form.processing} onClick={onClose}>Cancel</Button>
                    <Button variant="primary" disabled={!canSave} pending={form.processing} pendingLabel="Checking with Claude..." onClick={save} data-testid="account-save">
                        {isRotate ? 'Check and replace' : 'Check and save'}
                    </Button>
                </div>
            }
        >
            <div className="flex flex-col gap-5">
                {!isRotate && (
                    <RadioGroup
                        label="Secret type"
                        orientation="horizontal"
                        value={form.data.kind}
                        onChange={(value) => { form.setData((data) => ({ ...data, kind: value as AccountKind, secret: '' })); form.clearErrors(); }}
                        options={[
                            { value: 'setup_token', label: 'Setup token', description: 'Uses your Claude subscription' },
                            { value: 'api_key', label: 'API key', description: 'Billed per use by Anthropic' },
                        ]}
                    />
                )}

                <ol className="flex flex-col gap-3 text-[12.5px]">
                    <Step n={1}>
                        {isToken ? (
                            <div className="flex flex-col gap-2">
                                <p className="text-muted">Run this in a terminal on your computer and sign in when it asks. It prints a token. <DocsLink href="https://code.claude.com/docs/en/authentication">Claude Code setup</DocsLink></p>
                                <CommandBox />
                            </div>
                        ) : (
                            <p className="text-muted">Create a key in the Anthropic Console and copy it. Give it its own name there so you can revoke it later. <DocsLink href="https://console.anthropic.com/settings/keys">Open the Console</DocsLink></p>
                        )}
                    </Step>
                    <Step n={2}>
                        <Field label={isToken ? 'Paste the token' : 'Paste the API key'} error={secretError}>
                            <Textarea
                                rows={3}
                                className="font-mono text-[12px]"
                                spellCheck={false}
                                autoComplete="off"
                                disabled={form.processing}
                                value={form.data.secret}
                                onBlur={() => setIsTouched(true)}
                                onChange={(event) => { form.setData('secret', event.target.value); form.clearErrors('secret'); }}
                                placeholder={`${TOKEN_PREFIX[form.data.kind]}...`}
                                data-testid="account-secret"
                            />
                        </Field>
                        {form.processing && <p className="mt-1.5 text-[12px] text-muted" role="status">Sending a one-word test message on Haiku...</p>}
                    </Step>
                </ol>

                {!isRotate && (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Name" description={isSystem ? 'Everyone who can use it sees this name.' : 'Only you see this. It is how you tell accounts apart.'} error={form.errors.name}>
                                <TextInput disabled={form.processing} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder={isSystem ? 'Yak Geocodio' : 'Geocodio work'} />
                            </Field>
                            <Field label="Default model" description="Used when a task does not pick one.">
                                <Select disabled={form.processing} value={form.data.default_model} onChange={(value) => form.setData('default_model', value)} options={modelOptions(models)} />
                            </Field>
                        </div>
                        <AppearanceFields value={{ icon: form.data.icon, color: form.data.color }} onChange={(next) => form.setData((data) => ({ ...data, ...next }))} />
                        {!isSystem && <Checkbox checked={form.data.make_default} onCheckedChange={(checked) => form.setData('make_default', checked)} label="Make this my default account" />}
                        {!isToken && <p className="flex items-start gap-1.5 text-[12px] text-muted"><AlertTriangle size={13} className="mt-0.5 shrink-0 text-warn" aria-hidden="true" />Yak sets no spending cap on API keys. Tasks bill your Anthropic account as they run.</p>}
                    </>
                )}
            </div>
        </Dialog>
    );
}
