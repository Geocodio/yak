import type { ConversationItem } from './realtimeStub';

/** A collapsed step row such as "Read 2 files"; the detail opens on demand. */
export function ToolActivity({ item }: { item: ConversationItem }) {
    return (
        <details className="ml-9 text-[12px] text-muted" data-testid="thread-tool-activity">
            <summary className="cursor-pointer select-none">{item.text}</summary>
            {item.detail && <pre className="mt-1 overflow-auto rounded-card bg-panel-2 p-2 text-[11px] whitespace-pre-wrap">{item.detail}</pre>}
        </details>
    );
}
