import { StatusPill } from '@geocodio/console-ui';
import type { AccountStatus } from '@/types/claudeAccounts';

/** Healthy is implied, so only a problem gets a badge. */
export function StatusBadge({ status }: { status: AccountStatus }) {
    if (status === 'active') {
        return null;
    }

    return status === 'expired' ? <StatusPill tone="warn" label="Expired" /> : <StatusPill tone="fail" label="Needs a new secret" />;
}
