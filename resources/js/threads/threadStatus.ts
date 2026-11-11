import type { ThreadComposer, ThreadNotice } from '@/types/threads';

export type ThreadStatus = { label: string; tone: 'info' | 'warn' | 'fail' | 'brand' };

/** The header status pill for a thread; null when nothing needs calling out. */
export function threadStatus(notice: ThreadNotice | null, composerState: ThreadComposer['state']): ThreadStatus | null {
    if (notice !== null) {
        switch (notice.kind) {
            case 'restoring':
                return { label: 'Rebuilding', tone: 'info' };
            case 'restore_failed':
                return { label: 'Rebuild failed', tone: 'fail' };
            case 'waiting_for_account':
                return { label: 'Waiting for the account', tone: 'warn' };
            case 'waiting_for_sandbox':
                return { label: 'Waiting for a sandbox', tone: 'warn' };
            case 'account_invalid':
            case 'account_expired':
            case 'usage_limit':
            case 'credential_exposure':
                return { label: 'Token problem', tone: 'fail' };
            case 'guard_turns':
            case 'guard_time':
                return { label: 'Stopped', tone: 'warn' };
        }
    }

    return composerState === 'working' ? { label: 'Working', tone: 'brand' } : null;
}
