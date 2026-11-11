import { router } from '@inertiajs/react';
import { ConfirmDialog } from '@geocodio/console-ui';
import { destroy } from '@/routes/settings/claude-accounts';
import type { ClaudeAccount } from '@/types/claudeAccounts';

export function RemoveAccountDialog({ account, onClose }: { account: ClaudeAccount | null; onClose: () => void }) {
    return (
        <ConfirmDialog
            open={account !== null}
            onOpenChange={(open) => !open && onClose()}
            title={`Remove ${account?.name ?? ''}?`}
            body={account?.isSystem
                ? 'Repositories that use it fall back to the global Yak account. Threads already running on it are blocked until an admin adds an account again.'
                : 'Threads already running on it are blocked, and you will need to start a new thread to continue. The secret is deleted and cannot be recovered.'}
            confirmLabel="Remove account"
            onConfirm={() => account && router.delete(destroy.url(account.id), { preserveScroll: true, onSuccess: onClose })}
        />
    );
}
