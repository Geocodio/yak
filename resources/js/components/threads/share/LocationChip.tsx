import { Laptop } from 'lucide-react';
import type { ThreadData } from '@/types/threads';

/** Where the thread was started, shown only for a thread that began on the owner's computer. */
export function LocationChip({ thread }: { thread: ThreadData }) {
    if (!thread.startedOnDevice) {
        return null;
    }

    return (
        <span data-testid="location-chip" className="inline-flex items-center gap-1 rounded-pill border border-hair bg-panel-2 px-2 py-0.5 text-[11px] font-medium text-muted">
            <Laptop size={11} aria-hidden="true" />
            On your computer
        </span>
    );
}
