export type DeviceApprovalState = 'enter' | 'enter-error' | 'confirm' | 'approved' | 'denied' | 'expired';

/** Mirrors the array shape documented on App\Http\Resources\DeviceApprovalData. */
export type DeviceApproval = {
    state: DeviceApprovalState;
    code: string | null;
    name: string | null;
    os: 'darwin' | 'linux' | null;
    arch: 'arm64' | 'x86_64' | null;
    cli_version: string | null;
    requested_at: string | null;
    email: string;
};

/** Mirrors the array shape documented on App\Http\Resources\DeviceData. */
export type Device = {
    id: number;
    name: string;
    os: string;
    arch: string;
    cli_version: string | null;
    paired_at: string;
    last_seen_at: string | null;
    is_stale: boolean;
    stale_days: number | null;
    last_teleport: { thread_id: string; title: string; at: string } | null;
    owned_threads: { id: string; title: string }[];
};
