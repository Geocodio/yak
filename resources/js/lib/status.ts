export type TaskStatus =
    | 'pending'
    | 'running'
    | 'awaiting_clarification'
    | 'awaiting_ci'
    | 'retrying'
    | 'success'
    | 'failed'
    | 'expired'
    | 'cancelled';

export type Tone = 'ok' | 'warn' | 'fail' | 'info' | 'idle';

export const STATUS: Record<TaskStatus, { label: string; tone: Tone; live?: boolean }> = {
    pending: { label: 'Pending', tone: 'idle' },
    running: { label: 'Running', tone: 'info', live: true },
    awaiting_clarification: { label: 'Awaiting clarification', tone: 'warn' },
    awaiting_ci: { label: 'Awaiting CI', tone: 'info', live: true },
    retrying: { label: 'Retrying', tone: 'warn', live: true },
    success: { label: 'Done', tone: 'ok' },
    failed: { label: 'Failed', tone: 'fail' },
    expired: { label: 'Expired', tone: 'idle' },
    cancelled: { label: 'Cancelled', tone: 'idle' },
};
