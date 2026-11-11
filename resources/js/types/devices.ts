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
