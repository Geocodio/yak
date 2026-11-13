import { ChevronRight } from 'lucide-react';
import { StepTile } from '@/components/activity/stepIcons';
import type { ConversationItem } from './conversationItem';

/** The files a message edited, folded to one line in the same style as a group of steps. */
export function EditsBlock({ item, onOpenChanges }: { item: ConversationItem; onOpenChanges?: (path?: string) => void }) {
    const files = item.files ?? [];

    return (
        <details className="activity-details ml-9 rounded-[10px] border border-hair bg-panel text-[13px]" data-testid="thread-edits">
            <summary className="flex cursor-pointer items-center gap-2 rounded-[10px] px-2.5 py-[9px] text-muted hover:bg-panel-2">
                <ChevronRight size={14} className="activity-chevron shrink-0 text-faint transition-transform duration-150" aria-hidden />
                <StepTile category="edit" />
                <span className="min-w-0 flex-1 font-semibold text-body">
                    Edited {files.length} {files.length === 1 ? 'file' : 'files'}
                </span>
                <span className="tnum text-[12px] text-ok">+{item.added ?? 0}</span>
                <span className="tnum text-[12px] text-fail">-{item.removed ?? 0}</span>
            </summary>
            <ul className="m-0 list-none border-t border-hair px-3 py-2 text-muted">
                {files.map((file) => (
                    <li key={file} className="truncate font-mono text-[12px] leading-[1.7]">
                        {onOpenChanges === undefined ? (
                            file
                        ) : (
                            <button type="button" onClick={() => onOpenChanges(file)} className="max-w-full cursor-pointer truncate rounded-[6px] px-1.5 text-left hover:bg-panel-2 hover:text-body" title="Review this change" data-testid="thread-edits-open-changes">
                                {file}
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </details>
    );
}
