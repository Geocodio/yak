import { Deferred, Head, router } from '@inertiajs/react';
import { Badge, Button, EmptyState, Skeleton } from '@geocodio/console-ui';
import { AlertTriangle, KeyRound, Lock, Plus, RefreshCw, Terminal } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { AccountDialog, type AccountDialogMode } from '@/components/accounts/AccountDialog';
import { AccountsSection, Card } from '@/components/accounts/AccountsSection';
import { AwakeShareCard, type AwakeShareRow } from '@/components/accounts/AwakeShareCard';
import { EditAccountDialog } from '@/components/accounts/EditAccountDialog';
import { RemoveAccountDialog } from '@/components/accounts/RemoveAccountDialog';
import { TeleportSetupDialog } from '@/components/teleport/TeleportSetupDialog';
import { SettingsLayout } from '@/layouts/SettingsLayout';
import type { PageProps } from '@/types/shared';
import type { ClaudeAccount, ModelFamily } from '@/types/claudeAccounts';

type Props = PageProps<{
    accounts?: ClaudeAccount[];
    yakAccounts?: ClaudeAccount[];
    canManageYakAccounts: boolean;
    awakeShare: AwakeShareRow[] | null;
    models: ModelFamily[];
    docsUrl: string;
}>;

const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

function DocsLink({ href, children }: { href: string; children: ReactNode }) {
    return <a href={href} target="_blank" rel="noreferrer" className={`whitespace-nowrap rounded-[3px] text-accent-text hover:underline ${FOCUS}`}>{children}</a>;
}

function Intro({ children }: { children: ReactNode }) {
    return <p className="mt-0.5 max-w-[640px] text-[12px] leading-relaxed text-muted">{children}</p>;
}

function LoadingRows() {
    return (
        <Card className="divide-y divide-hair" aria-busy="true" aria-label="Loading accounts" data-testid="accounts-loading">
            {[0, 1, 2].map((index) => (
                <div key={index} className="flex items-center gap-3 px-4 py-3.5">
                    <Skeleton className="size-8 rounded-control" />
                    <div className="flex flex-1 flex-col gap-2"><Skeleton className="h-3.5 w-40" /><Skeleton className="h-3 w-64 max-w-full" /></div>
                    <Skeleton className="h-8 w-24" />
                </div>
            ))}
        </Card>
    );
}

function EmptyAccounts({ docsUrl, sharedName, onAdd }: { docsUrl: string; sharedName?: string; onAdd: () => void }) {
    const tiles = [
        [Terminal, 'Use your Claude subscription', 'Run one command in your terminal and paste the token. Tasks use your plan\'s limits.'],
        [KeyRound, 'Use an API key', 'Paste a key from the Anthropic Console. Anthropic bills each task as it runs.'],
    ] as const;

    return (
        <Card className="p-5" data-testid="no-accounts">
            <div className="flex flex-col gap-4">
                <div>
                    <h3 className="text-[14px] font-semibold">Run your tasks on your own Claude account</h3>
                    <p className="mt-1 max-w-[480px] text-[12.5px] leading-relaxed text-muted">Until you add one, your tasks run on {sharedName ? `the shared Yak account, ${sharedName}` : 'a shared Yak account'}. With your own account, usage counts against your plan and your teammates cannot spend it.</p>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    {tiles.map(([Icon, title, text]) => (
                        <button key={title} type="button" onClick={onAdd} className={`flex flex-col items-start gap-2 rounded-card border border-hair bg-panel p-3.5 text-left hover:border-hair-strong hover:bg-panel-2 ${FOCUS}`}>
                            <span className="grid size-8 place-items-center rounded-control bg-panel-2 text-muted"><Icon size={15} aria-hidden="true" /></span>
                            <span className="text-[13px] font-medium">{title}</span>
                            <span className="text-[12px] leading-snug text-muted">{text}</span>
                        </button>
                    ))}
                </div>
                <p className="text-[12px] text-muted">Secrets are checked with a test message, stored encrypted and never shown again. <DocsLink href={docsUrl}>Read how accounts work</DocsLink></p>
            </div>
        </Card>
    );
}

export default function ClaudeAccounts({ accounts, yakAccounts, canManageYakAccounts, awakeShare, models, docsUrl }: Props) {
    const [dialog, setDialog] = useState<{ mode: AccountDialogMode; account?: ClaudeAccount } | null>(null);
    const [editing, setEditing] = useState<ClaudeAccount | null>(null);
    const [removing, setRemoving] = useState<ClaudeAccount | null>(null);
    const [isTeleportOpen, setIsTeleportOpen] = useState(false);
    const [didLoadFail, setDidLoadFail] = useState(false);

    useEffect(() => {
        const fail = () => setDidLoadFail(true);
        const offs = [router.on('httpException', fail), router.on('networkError', fail)];

        return () => offs.forEach((off) => off());
    }, []);

    const retry = () => {
        setDidLoadFail(false);
        router.reload({ only: ['accounts', 'yakAccounts'] });
    };

    const rowProps = {
        models,
        onRotate: (account: ClaudeAccount) => setDialog({ mode: 'rotate', account }),
        onEdit: setEditing,
        onRemove: setRemoving,
    };
    const hasDefault = (accounts ?? []).some((account) => account.isDefault);
    const isLoaded = accounts !== undefined && yakAccounts !== undefined;

    const failedCard = (
        <Card className="p-4" data-testid="accounts-load-failed">
            <EmptyState icon={<AlertTriangle size={18} />} title="Could not load your accounts" body="Yak could not reach its server, so the list is missing. Your accounts are safe. Check your connection and try again." action={<Button variant="secondary" icon={<RefreshCw size={13} />} onClick={retry}>Try again</Button>} />
        </Card>
    );

    return (
        <>
            <Head title="Claude accounts" />

            <div className="flex flex-col gap-8">
                <div>
                    <p className="max-w-[620px] text-[13px] leading-relaxed text-muted">Secrets are stored encrypted and never shown again.</p>
                </div>

                <section className="flex flex-col gap-3" aria-labelledby="mine-heading">
                    <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
                        <div>
                            <h3 id="mine-heading" className="text-[14px] font-semibold">Your accounts</h3>
                            <Intro>Tasks you start run on one of these. Your default is used wherever you have not picked an account for a repository. <DocsLink href={docsUrl}>How accounts work</DocsLink></Intro>
                        </div>
                        {isLoaded && accounts.length > 0 && <Button variant="primary" icon={<Plus size={14} />} onClick={() => setDialog({ mode: 'add' })} data-testid="add-account">Add account</Button>}
                    </div>
                    {didLoadFail && !isLoaded ? failedCard : (
                        <Deferred data={['accounts', 'yakAccounts']} fallback={<LoadingRows />}>
                            {accounts && accounts.length > 0
                                ? <AccountsSection accounts={accounts} {...rowProps} />
                                : <EmptyAccounts docsUrl={docsUrl} sharedName={yakAccounts?.find((account) => account.status === 'active')?.name} onAdd={() => setDialog({ mode: 'add' })} />}
                        </Deferred>
                    )}
                </section>

                {isLoaded && (
                    <section className="flex flex-col gap-3" data-testid="system-accounts" aria-labelledby="system-heading">
                        <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
                            <div>
                                <h3 id="system-heading" className="flex items-center gap-2 text-[14px] font-semibold">
                                    Yak accounts
                                    {!canManageYakAccounts && <Badge tone="neutral"><Lock size={10} className="mr-1 inline" aria-hidden="true" />Admins only</Badge>}
                                </h3>
                                <Intro>Shared accounts that Yak runs work on when nobody started it, like Sentry alerts and flaky tests. You can also pick one when you start a task. {canManageYakAccounts ? 'Pick each repository\'s account on its settings page.' : 'Only Yak admins can add, replace or remove them.'}</Intro>
                            </div>
                            {canManageYakAccounts && yakAccounts.length > 0 && <Button variant="secondary" icon={<Plus size={14} />} onClick={() => setDialog({ mode: 'addSystem' })} data-testid="add-yak-account">Add Yak account</Button>}
                        </div>
                        {yakAccounts.length > 0
                            ? <AccountsSection accounts={yakAccounts} readOnly={!canManageYakAccounts} {...rowProps} />
                            : <Card className="p-4"><EmptyState title="No Yak account yet" body="Work nobody started, like Sentry alerts, cannot run until an admin adds one." action={canManageYakAccounts ? <Button variant="primary" icon={<Plus size={14} />} onClick={() => setDialog({ mode: 'addSystem' })} data-testid="add-yak-account">Add Yak account</Button> : undefined} /></Card>}
                    </section>
                )}

                {awakeShare !== null && (
                    <section className="flex flex-col gap-3" aria-labelledby="awake-heading">
                        <h3 id="awake-heading" className="text-[14px] font-semibold">Awake share</h3>
                        <AwakeShareCard rows={awakeShare} />
                    </section>
                )}
            </div>

            {dialog && <AccountDialog key={`${dialog.mode}-${dialog.account?.id ?? 'new'}`} mode={dialog.mode} account={dialog.account} models={models} hasDefault={hasDefault} onClose={() => setDialog(null)} onSetUpTeleport={() => setIsTeleportOpen(true)} />}
            {isTeleportOpen && <TeleportSetupDialog open onClose={() => setIsTeleportOpen(false)} />}
            {editing && <EditAccountDialog key={editing.id} account={editing} models={models} onClose={() => setEditing(null)} />}
            <RemoveAccountDialog account={removing} onClose={() => setRemoving(null)} />
        </>
    );
}

ClaudeAccounts.layout = (page: ReactNode) => <SettingsLayout slug="claude-accounts">{page}</SettingsLayout>;
