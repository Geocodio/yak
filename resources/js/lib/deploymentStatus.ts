import type { Tone } from '@/lib/status';

/** Maps `App\Enums\DeploymentStatus` values to a `StatusPill` tone. */
export const STATUS_TONE: Record<string, Tone> = {
    pending: 'idle',
    starting: 'info',
    running: 'ok',
    hibernated: 'idle',
    destroying: 'warn',
    destroyed: 'idle',
    failed: 'fail',
};
