import type { ConversationItem } from './realtimeStub';

export function EditsBlock({ item }: { item: ConversationItem }) {
    const files = item.files ?? [];

    return (
        <details className="ml-9 rounded-card border border-hair bg-panel text-[12px]" data-testid="thread-edits">
            <summary className="flex cursor-pointer items-center gap-2 px-3 py-2 select-none">
                <span className="text-body">Edited {files.length} {files.length === 1 ? 'file' : 'files'}</span>
                <span className="text-ok">+{item.added ?? 0}</span>
                <span className="text-fail">-{item.removed ?? 0}</span>
            </summary>
            <ul className="border-t border-hair px-3 py-2 text-muted">
                {files.map((file) => (
                    <li key={file} className="truncate font-mono text-[11px]">{file}</li>
                ))}
            </ul>
        </details>
    );
}
