import { ChevronRight } from 'lucide-react';
import type { ActivityRow } from '@/types/tasks';

/** The newest activity row as one tappable line on the Overview tab; tapping switches to the Activity tab. */
export function LiveLine({ row, entries, onOpen }: { row: ActivityRow | null; entries: number; onOpen: () => void }) {
    if (!row) {
        return null;
    }
    return (
        <button type="button" onClick={onOpen} data-testid="live-line" className="flex w-full items-center gap-3 rounded-card border border-hair bg-panel px-3.5 py-3 text-left shadow-card">
            <span className="h-2 w-2 shrink-0 rounded-full bg-info shadow-[0_0_0_4px_rgba(47,111,219,0.15)]" />
            <span className="min-w-0 flex-1">
                <span className="block truncate text-[13px] text-body">{row.text}</span>
                <span className="block text-[11px] text-faint">
                    {row.at} · {entries.toLocaleString()} entries · tap for activity
                </span>
            </span>
            <ChevronRight size={16} className="shrink-0 text-faint" />
        </button>
    );
}
