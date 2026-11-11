import { cn } from '@geocodio/console-ui';

const SEGMENTS = 5;

export function ChangeBar({ additions, deletions }: { additions: number; deletions: number }) {
    const total = additions + deletions;
    const addedSegments = total === 0 ? 0 : Math.round((additions / total) * SEGMENTS);
    const removedSegments = total === 0 ? 0 : SEGMENTS - addedSegments;

    return (
        <span aria-hidden className="inline-flex gap-0.5" data-testid="changes-change-bar">
            {Array.from({ length: SEGMENTS }, (_, index) => (
                <span key={index} className={cn('h-2 w-2 rounded-[2px]', index < addedSegments ? 'bg-ok' : index < addedSegments + removedSegments ? 'bg-fail' : 'bg-hair')} />
            ))}
        </span>
    );
}
