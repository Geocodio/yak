import { cn } from '@geocodio/console-ui';

/** One segment per awake slot, the first `awake` filled. */
export function StackAwakeMeter({ awake, max }: { awake: number; max: number }) {
    return (
        <div className="flex gap-1" data-testid="stack-awake-meter" aria-hidden>
            {Array.from({ length: Math.max(max, 0) }, (_, index) => (
                <span key={index} className={cn('h-1.5 flex-1 rounded-pill', index < awake ? 'bg-brand' : 'bg-hair')} data-filled={index < awake} />
            ))}
        </div>
    );
}
