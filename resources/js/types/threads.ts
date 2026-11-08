export type ThreadAccount = { id: number; name: string; isSystem: boolean };

export type ThreadBlockReason = 'no_valid_account' | 'invalid_token' | 'usage_limit' | 'waiting_for_sandbox';

export type ThreadPanelData = {
    sandboxState: 'running' | 'paused' | 'stopped' | 'none';
    account: ThreadAccount | null;
    model: string;
    /** Empty for a viewer who may not switch the thread. */
    switchableAccounts: ThreadAccount[];
    models: string[];
    blocked: { reason: ThreadBlockReason; message: string } | null;
    guardStop: { reason: 'assistant_turns' | 'wall_clock'; limit: number } | null;
    canContinue: boolean;
};
