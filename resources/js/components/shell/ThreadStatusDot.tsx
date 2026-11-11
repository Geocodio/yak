import { cn } from '@geocodio/console-ui';
import type { ThreadListRow } from '@/types/shell';

/** Running purple filled, paused hollow, stopped grey hollow, blocked red, needs-you question orange. */
export function ThreadStatusDot({ row }: { row: Pick<ThreadListRow, 'status' | 'needsYouReason'> }) {
    const isQuestion = row.needsYouReason === 'question';
    const className = isQuestion
        ? 'bg-warn'
        : row.status === 'running'
          ? 'bg-brand'
          : row.status === 'blocked'
            ? 'bg-fail'
            : row.status === 'paused'
              ? 'border border-brand'
              : 'border border-idle';

    return <span aria-hidden className={cn('inline-block h-2 w-2 shrink-0 rounded-pill', className)} data-status={row.status} />;
}
