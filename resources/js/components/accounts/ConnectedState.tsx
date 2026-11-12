import { Button } from '@geocodio/console-ui';
import { Check } from 'lucide-react';
import { formatDate, modelLabel } from './accountAppearance';

export type ConnectedAccount = { name: string; kind: string; defaultModel: string | null; expiresAt: string | null };

type Props = {
    account: ConnectedAccount;
    isSystem: boolean;
    hasDevice: boolean;
    onSetUpTeleport?: () => void;
};

/** What the person sees after a successful connect: the facts, and the optional Teleport step. */
export function ConnectedState({ account, isSystem, hasDevice, onSetUpTeleport }: Props) {
    const rows: [string, string][] = [
        ['Type', account.kind === 'setup_token' ? 'Setup token, Claude subscription' : 'API key, billed by Anthropic'],
        ['Default model', modelLabel(account.defaultModel)],
        ['Expires', account.expiresAt === null ? 'Does not expire.' : `${formatDate(account.expiresAt)}. Yak reminds you 14 days before.`],
    ];

    return (
        <div className="flex flex-col gap-6" data-testid="account-connected">
            <div className="flex flex-col items-center gap-3 pt-2 text-center">
                <span className="grid size-11 place-items-center rounded-pill bg-ok-soft text-ok"><Check size={22} aria-hidden="true" /></span>
                <h3 className="text-[18px] font-semibold">{isSystem ? 'Yak account connected' : 'Claude account connected'}</h3>
                <p className="max-w-[400px] text-[13px] leading-relaxed text-muted">
                    Claude answered the test message. {isSystem ? 'Work nobody started now runs on ' : 'Tasks you start now run on '}
                    <span className="font-medium text-body">{account.name}</span>.
                </p>
            </div>

            <dl className="grid grid-cols-[110px_1fr] gap-y-2 rounded-card bg-panel-2 px-4 py-3.5 text-[13px]">
                {rows.map(([label, value]) => <div key={label} className="contents"><dt className="text-muted">{label}</dt><dd>{value}</dd></div>)}
            </dl>

            {onSetUpTeleport && !hasDevice && (
                <div className="flex flex-col gap-2.5 rounded-card border border-hair p-4" data-testid="connected-teleport-card">
                    <span className="text-[11px] font-semibold uppercase tracking-wide text-muted">Next, optional</span>
                    <h4 className="text-[14px] font-semibold">Set up Teleport</h4>
                    <p className="text-[13px] leading-relaxed text-muted">Teleport moves a thread between Yak and Claude Code on your computer, in both directions. Start on Yak and finish at your desk, or hand a local session to Yak.</p>
                    <div className="flex items-center gap-3">
                        <Button variant="primary" onClick={onSetUpTeleport} data-testid="connected-set-up-teleport">Set up Teleport</Button>
                        <span className="text-[12px] text-muted">One command, about a minute</span>
                    </div>
                </div>
            )}
        </div>
    );
}
