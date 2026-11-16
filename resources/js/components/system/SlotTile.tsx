import { cn } from '@geocodio/console-ui';

/** One awake sandbox slot: initials of the person holding it, or an empty outline. */
export function SlotTile({ initials, toneClass }: { initials: string | null; toneClass?: string }) {
    return (
        <span
            data-testid={initials === null ? 'slot-free' : 'slot-used'}
            className={cn(
                'flex h-9 w-9 items-center justify-center rounded-control text-[12px] font-medium',
                initials === null ? 'border border-dashed border-hair text-faint' : cn('text-body', toneClass),
            )}
        >
            {initials}
        </span>
    );
}
