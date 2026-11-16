import { Badge, Button } from '@geocodio/console-ui';
import { KeyRound, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import type { SshKey } from '@/types/sshKeys';

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['week', 604_800],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
];

export function timeAgo(iso: string): string {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    const [unit, size] = UNITS.find(([, size]) => Math.abs(seconds) >= size) ?? ['minute', 60];

    return Math.abs(seconds) < 60 ? 'just now' : formatter.format(Math.round(seconds / size), unit);
}

export function KeyRow({ sshKey, onRemove }: { sshKey: SshKey; onRemove?: (sshKey: SshKey) => void }): ReactNode {
    return (
        <li className="flex items-center gap-3 px-4 py-3.5" data-testid="ssh-key-row">
            <span className="grid size-8 shrink-0 place-items-center rounded-control bg-panel-2 text-muted">
                <KeyRound size={15} aria-hidden="true" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-[13.5px] font-medium">{sshKey.name}</span>
                    <Badge>{sshKey.type}</Badge>
                </div>
                <code className="truncate font-mono text-[12px] text-muted">{sshKey.fingerprint}</code>
                <p className="text-[12px] text-muted">
                    {sshKey.source === 'github' ? 'Added to GitHub' : 'Added'} {timeAgo(sshKey.addedAt)}
                </p>
            </div>
            {onRemove && (
                <Button variant="tertiary" icon={<Trash2 size={13} />} onClick={() => onRemove(sshKey)} data-testid="ssh-key-remove">
                    Remove
                </Button>
            )}
        </li>
    );
}
