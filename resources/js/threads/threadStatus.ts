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
            case 'waiting_for_repository':
                return { label: 'Waiting for repository setup', tone: 'warn' };
            case 'repository_setup_failed':
                return { label: 'Repository setup failed', tone: 'fail' };
            case 'account_invalid':
            case 'account_expired':
            case 'credential_exposure':
                return { label: 'Token problem', tone: 'fail' };
            case 'usage_limit':
                return { label: 'Usage limit', tone: 'fail' };
            case 'guard_turns':
            case 'guard_time':
                return { label: 'Stopped', tone: 'warn' };
        }
    }

    return composerState === 'working' ? { label: 'Working', tone: 'brand' } : null;
}

/** Where a rebuild stands: the step being worked on (or the failed one), the step count and its label. */
export function rebuildProgress(notice: ThreadNotice): { current: number; total: number; label: string | null } | null {
    if (notice.kind !== 'restoring' && notice.kind !== 'restore_failed') {
        return null;
    }

    const steps = notice.restoreSteps;
    const failedIndex = steps.findIndex((step) => step.status === 'failed');
    const doneCount = steps.filter((step) => step.status === 'done').length;
    const index = failedIndex !== -1 ? failedIndex : Math.min(doneCount, steps.length - 1);

    return { current: Math.max(index + 1, 1), total: steps.length, label: steps[index]?.label ?? null };
}
