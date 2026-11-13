import { cn } from '@geocodio/console-ui';
import { YakMarkFace } from './YakMark';

/** Yak's avatar in a conversation: the head on a soft rust tile. */
export function YakAvatar({ className }: { className?: string }) {
    return (
        <span className={cn('grid size-[22px] flex-none place-items-center rounded-[7px] bg-accent-soft', className)} data-testid="yak-avatar">
            <YakMarkFace className="size-[18px]" />
        </span>
    );
}
