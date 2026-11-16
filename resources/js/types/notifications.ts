export type PushEventKey = 'needs_you' | 'turn_finished' | 'stack_usage' | 'pull_request';

/** Mirrors the array shape documented on App\Http\Resources\NotificationSettingsData. */
export type PushDevice = {
    id: number;
    label: string | null;
    userAgent: string | null;
    isEnabled: boolean;
    lastUsedAt: string | null;
    lastError: string | null;
    isCurrent: boolean;
};

export type NotificationSettings = {
    events: Record<PushEventKey, boolean>;
    devices: PushDevice[];
    vapidPublicKey: string | null;
    isIosSupported: boolean;
};
