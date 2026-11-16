import type { ReactNode } from 'react';
import type { ClaudeAccount, ModelFamily } from '@/types/claudeAccounts';
import { AccountRow, ColumnHeads } from './AccountRow';

export const Card = ({ children, className = '', ...rest }: { children: ReactNode; className?: string } & React.HTMLAttributes<HTMLDivElement>) => (
    <div className={`rounded-card border border-hair bg-panel shadow-card ${className}`} {...rest}>{children}</div>
);

type Props = {
    accounts: ClaudeAccount[];
    models: ModelFamily[];
    readOnly?: boolean;
    onRotate: (account: ClaudeAccount) => void;
    onEdit: (account: ClaudeAccount) => void;
    onRemove: (account: ClaudeAccount) => void;
};

/** A card of account rows under the shared column heads. */
export function AccountsSection({ accounts, ...rowProps }: Props) {
    return (
        <Card className="overflow-hidden">
            <ColumnHeads />
            <div className="divide-y divide-hair">
                {accounts.map((account) => <AccountRow key={account.id} account={account} {...rowProps} />)}
            </div>
        </Card>
    );
}
