/** Close codes the terminal bridge sends; see broker/PROTOCOL.md section 9. */
export const CLOSE_NORMAL = 1000;
export const CLOSE_FRAME_TOO_LARGE = 1009;
export const CLOSE_REFUSED = 4403;
export const CLOSE_IDLE = 4408;
export const CLOSE_TOO_MANY = 4429;

export const RECONNECT_DELAYS_MS = [1000, 2000, 4000, 8000];
export const MAX_RECONNECT_TRIES = 5;

/** Delay before reconnect try `attempt` (0-based); the fifth try reuses the longest delay. */
export function backoffDelay(attempt: number): number {
    return RECONNECT_DELAYS_MS[Math.min(attempt, RECONNECT_DELAYS_MS.length - 1)];
}

export type CloseOutcome = 'finished' | 'refused' | 'too_many' | 'retry';

/** Decides what a socket close means: ended on purpose, refused for good, or worth a reconnect. */
export function classifyClose(code: number): CloseOutcome {
    if (code === CLOSE_NORMAL) {
        return 'finished';
    }
    if (code === CLOSE_REFUSED || code === CLOSE_FRAME_TOO_LARGE) {
        return 'refused';
    }
    if (code === CLOSE_TOO_MANY) {
        return 'too_many';
    }

    return 'retry';
}

export function encodeResize(cols: number, rows: number): string {
    const clamp = (value: number) => Math.min(500, Math.max(1, Math.round(value)));

    return JSON.stringify({ type: 'resize', cols: clamp(cols), rows: clamp(rows) });
}

export type ControlMessage = { type: 'exit'; code: number } | { type: 'unknown' };

/** Parses a text frame from the bridge; anything unrecognised is `unknown`. */
export function decodeControl(text: string): ControlMessage {
    try {
        const body = JSON.parse(text) as { type?: unknown; code?: unknown };
        if (body.type === 'exit') {
            return { type: 'exit', code: typeof body.code === 'number' ? body.code : 0 };
        }
    } catch {
        // Not JSON; treated as unknown.
    }

    return { type: 'unknown' };
}

export type SessionRefusal = 'forbidden' | 'thread_closed' | 'thread_owned_by_device' | 'sandbox_destroyed' | 'no_sandbox' | 'origin_not_allowed' | 'not_found';

/** User-facing text for the endpoint's HTTP status and error code. */
export function refusalReason(status: number, error: string | null): string {
    switch (error) {
        case 'thread_closed':
            return 'This thread is closed.';
        case 'thread_owned_by_device':
            return 'This thread is running on a computer.';
        case 'sandbox_destroyed':
            return 'The sandbox was destroyed.';
        case 'no_sandbox':
            return 'This thread has no sandbox yet.';
        case 'forbidden':
            return 'You need driving rights on this thread to open a shell.';
    }

    return status === 404 ? 'This thread could not be found.' : 'The terminal session was refused.';
}
