import { ChevronRight } from 'lucide-react';
import type { ConversationItem } from './conversationItem';

/** A collapsed step row such as "Read 2 files"; the detail opens on demand. */
export function ToolActivity({ item }: { item: ConversationItem }) {
    return (
        <details className="group ml-9 text-[12px] text-muted" data-testid="thread-tool-activity">
            <summary className="flex cursor-pointer list-none items-center gap-1 select-none">
                <ChevronRight size={12} className="transition-transform group-open:rotate-90" data-testid="tool-activity-chevron" />
                {item.text}
                {item.durationMs !== undefined && <span> in {Math.max(1, Math.round(item.durationMs / 1000))} s</span>}
            </summary>
            {item.detail && <pre className="mt-1 overflow-auto rounded-card bg-panel-2 p-2 text-[11px] whitespace-pre-wrap">{item.detail}</pre>}
        </details>
    );
}
