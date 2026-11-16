import { cn } from '@geocodio/console-ui';
import type { DiffFileStatus } from '@/types/thread-changes';

const LETTER: Record<DiffFileStatus, string> = { added: 'A', modified: 'M', deleted: 'D', renamed: 'R' };
const TONE: Record<DiffFileStatus, string> = { added: 'bg-ok/15 text-ok', modified: 'bg-info/15 text-info', deleted: 'bg-fail/15 text-fail', renamed: 'bg-warn/15 text-warn' };

export function StatusTile({ status }: { status: DiffFileStatus }) {
    return (
        <span data-testid="changes-status-tile" title={status} className={cn('inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-chip text-[10px] font-semibold', TONE[status])}>
            {LETTER[status]}
        </span>
    );
}
