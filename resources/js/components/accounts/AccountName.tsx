import { cn } from '@geocodio/console-ui';
import type { Appearance } from '@/types/claudeAccounts';
import { AccountMark } from './AccountMark';

/** The account with its mark, for sentences and sidebars. */
export function AccountName({ account, size = 18, className }: { account: Appearance & { name: string }; size?: number; className?: string }) {
    return (
        <span className={cn('inline-flex min-w-0 items-center gap-1.5', className)}>
            <AccountMark appearance={account} size={size} />
            <span className="truncate">{account.name}</span>
        </span>
    );
}
