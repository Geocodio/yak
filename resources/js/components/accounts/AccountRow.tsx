import { router } from '@inertiajs/react';
import { Badge, Button, Menu, Select, Tooltip, cn } from '@geocodio/console-ui';
import { KeyRound, MoreHorizontal, Pencil, RefreshCw, Star, Terminal, Trash2 } from 'lucide-react';
import { update } from '@/routes/settings/claude-accounts';
import type { ClaudeAccount } from '@/types/claudeAccounts';
import { daysUntil, formatAgo, formatDate, KIND_LABEL, modelLabel, modelOptions } from './accountAppearance';
import { AccountMark } from './AccountMark';
import { StatusBadge } from './StatusBadge';

export const ACCOUNT_GRID = 'grid-cols-2 md:grid-cols-[minmax(0,1fr)_112px_150px_216px]';

export function ColumnHeads() {
    return (
        <div className={cn('hidden gap-x-4 border-b border-hair px-4 py-2 text-[11.5px] font-medium text-faint md:grid', ACCOUNT_GRID)} aria-hidden="true">
            <span>Account</span><span>Default model</span><span>Secret expires</span><span />
        </div>
    );
}

type Props = {
    account: ClaudeAccount;
    models: string[];
    readOnly?: boolean;
    onRotate: (account: ClaudeAccount) => void;
    onEdit: (account: ClaudeAccount) => void;
    onRemove: (account: ClaudeAccount) => void;
};

export function AccountRow({ account, models, readOnly, onRotate, onEdit, onRemove }: Props) {
    const isUsable = account.status === 'active';
    const needsAttention = !isUsable || account.expiresSoon;
    const KindIcon = account.kind === 'setup_token' ? Terminal : KeyRound;
    const patch = (data: Record<string, unknown>) => router.patch(update.url(account.id), data as never, { preserveScroll: true });

    return (
        <div className={cn('grid items-center gap-x-4 gap-y-3 px-4 py-3.5', ACCOUNT_GRID)} data-testid={`account-${account.id}`}>
            <div className="col-span-2 flex min-w-0 items-start gap-3 md:col-span-1">
                <AccountMark appearance={account} size={36} dimmed={!isUsable} />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13.5px] font-medium">
                        <span className="truncate">{account.name}</span>
                        {account.isDefault && <Badge tone="accent">Default</Badge>}
                        <StatusBadge status={account.status} />
                    </div>
                    <p className="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-[12px] text-muted">
                        <KindIcon size={11} className="text-faint" aria-hidden="true" />{KIND_LABEL[account.kind]}<span aria-hidden="true">·</span>Last used {formatAgo(account.lastUsedAt)}
                    </p>
                    {account.unattendedRepos.length > 0 && (
                        <p className="mt-1 text-[12px] text-muted">
                            Runs unattended work on {account.unattendedRepos.map((repo, index) => <span key={repo}>{index > 0 && ', '}<span className="font-mono text-[11.5px]">{repo}</span></span>)}
                        </p>
                    )}
                </div>
            </div>
            <div className="text-[12.5px]">
                {readOnly ? <span className="text-muted">{modelLabel(account.defaultModel)}</span> : (
                    <Tooltip label="The model tasks use on this account unless they pick another." side="top">
                        <Select aria-label={`Default model for ${account.name}`} className="w-[104px]" value={account.defaultModel} onChange={(value) => value && patch({ default_model: value })} options={modelOptions(models)} />
                    </Tooltip>
                )}
            </div>
            <div className="text-[12.5px] text-muted">
                <span className="text-faint md:hidden">Expires </span>{account.expiresAt === null ? 'does not expire' : formatDate(account.expiresAt)}
                {isUsable && account.expiresSoon && <div className="mt-0.5"><Badge tone="warn">Expires in {daysUntil(account.expiresAt)} days</Badge></div>}
            </div>
            <div className="col-span-2 flex items-center gap-2 md:col-span-1 md:justify-end">
                {!readOnly && (
                    <>
                        <Button variant={needsAttention ? 'primary' : 'secondary'} icon={<RefreshCw size={13} />} onClick={() => onRotate(account)}>Replace secret</Button>
                        <Menu
                            trigger={<MoreHorizontal size={15} />}
                            aria-label={`More actions for ${account.name}`}
                            className="h-8 w-8 px-0"
                            align="end"
                            items={[
                                { key: 'edit', label: 'Edit name and appearance', icon: <Pencil size={13} />, onSelect: () => onEdit(account) },
                                ...(!account.isSystem && isUsable && !account.isDefault ? [{ key: 'default', label: 'Make default', icon: <Star size={13} />, onSelect: () => patch({ is_default: true }) }] : []),
                                { key: 'remove', label: 'Remove account', icon: <Trash2 size={13} />, danger: true, dividerAbove: true, onSelect: () => onRemove(account) },
                            ]}
                        />
                    </>
                )}
            </div>
            {(account.lastError || (isUsable && account.expiresSoon)) && (
                <p className={cn('col-span-full -mt-1 rounded-control px-3 py-2 text-[12px] leading-snug md:ml-12', account.lastError ? 'bg-fail-soft text-body' : 'bg-warn-soft text-body')} data-testid={`account-${account.id}-notice`}>
                    {account.lastError ? `${account.lastError} Replace the secret to use this account again. Threads on it stay blocked until you do.` : 'Setup tokens last a year. Replace this one before it runs out so tasks keep going.'}
                </p>
            )}
        </div>
    );
}
