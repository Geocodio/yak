import { Tooltip, cn } from '@geocodio/console-ui';
import { STATUS, type TaskStatus, type Tone } from '@/lib/status';

const TONE_BG: Record<Tone, string> = { ok: 'bg-ok', warn: 'bg-warn', fail: 'bg-fail', info: 'bg-info', idle: 'bg-idle' };

export function StatusDot({ status }: { status: TaskStatus }) {
    const s = STATUS[status];
    return (
        <Tooltip label={s.label}>
            <span className="relative flex h-3 w-3 items-center justify-center">
                {s.live && <span className={cn('absolute inline-flex h-full w-full animate-ping rounded-pill opacity-40', TONE_BG[s.tone])} />}
                <span className={cn('relative inline-block h-2 w-2 rounded-pill', TONE_BG[s.tone])} />
            </span>
        </Tooltip>
    );
}
