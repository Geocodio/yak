import type { ReactNode } from 'react';
import { Card } from '@/components/accounts/AccountsSection';
import type { SshKey } from '@/types/sshKeys';
import { KeyRow } from './KeyRow';

export function KeyList({ keys, onRemove, empty, testId }: { keys: SshKey[]; onRemove?: (sshKey: SshKey) => void; empty: ReactNode; testId: string }) {
    if (keys.length === 0) {
        return (
            <Card className="px-4 py-5 text-[12.5px] text-muted" data-testid={testId}>
                {empty}
            </Card>
        );
    }

    return (
        <Card data-testid={testId}>
            <ul className="divide-y divide-hair">
                {keys.map((sshKey) => (
                    <KeyRow key={sshKey.id} sshKey={sshKey} onRemove={onRemove} />
                ))}
            </ul>
        </Card>
    );
}
