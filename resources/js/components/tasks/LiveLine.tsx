import { ChevronRight } from 'lucide-react';
import { formatAgo } from '@/lib/format';
import type { ActivityRow } from '@/types/tasks';

/**
 * The newest activity row as one tappable line on the Overview tab; tapping
 * switches to the Activity tab. The age is computed from `createdAt` on each
 * render, and the page re-renders on every poll, so it keeps advancing.
 */
export function LiveLine({ row, entries, onOpen }: { row: ActivityRow | null; entries: number; onOpen: () => void }) {
    if (!row) {
        return null;
    }
    return (
        <button type="button" onClick={onOpen} data-testid="live-line" className="flex w-full items-center gap-3 rounded-card border border-hair bg-panel px-3.5 py-3 text-left shadow-card">
            <span className="h-2 w-2 shrink-0 rounded-full bg-info ring-4 ring-info/15" />
            <span className="min-w-0 flex-1">
                <span className="block truncate text-[13px] text-body">{row.text}</span>
                <span className="block text-[11px] text-faint">
                    {formatAgo(row.createdAt)} · {entries.toLocaleString()} entries · tap for activity
                </span>
            </span>
            <ChevronRight size={16} className="shrink-0 text-faint" />
        </button>
    );
}
