import { cn } from '@geocodio/console-ui';
import type { ThreadPreviewTarget } from '@/types/threads';

type Props = { targets: ThreadPreviewTarget[]; selectedId: number; onSelect: (threadId: number) => void };

/** Tabs for the thread and its sub-threads that have their own preview. Hidden when there is only one. */
export function TargetSwitcher({ targets, selectedId, onSelect }: Props) {
    if (targets.length < 2) {
        return null;
    }

    return (
        <div role="tablist" aria-label="Preview of" className="flex shrink-0 gap-4 overflow-x-auto border-b border-hair px-3" data-testid="preview-targets">
            {targets.map((target) => (
                <button
                    key={target.threadId}
                    type="button"
                    role="tab"
                    aria-selected={target.threadId === selectedId}
                    onClick={() => onSelect(target.threadId)}
                    data-testid={`preview-target-${target.threadId}`}
                    className={cn('flex h-9 shrink-0 cursor-pointer items-center gap-1.5 text-[13px]', target.threadId === selectedId ? 'font-medium text-body' : 'text-muted')}
                >
                    <span className="max-w-[16rem] truncate">{target.title}</span>
                    <span className="text-[11px] text-faint">{target.kind}</span>
                </button>
            ))}
        </div>
    );
}
