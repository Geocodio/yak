import { Loader2 } from 'lucide-react';
import type { ConversationItem } from './conversationItem';

/** Live block shown while the latest step is still running. */
export function WorkingBlock({ items }: { items: ConversationItem[] }) {
    return (
        <div className="ml-9 rounded-card border border-hair bg-panel-2 p-3 text-[12px]" data-testid="thread-working-block">
            <div className="flex items-center gap-2 font-medium text-body">
                <Loader2 size={12} className="animate-spin" />
                Working
            </div>
            <ul className="mt-2 flex flex-col gap-1 text-muted">
                {items.map((item) => (
                    <li key={item.key} className="flex justify-between gap-3">
                        <span>{item.running ? `Running: ${item.text}` : item.text}</span>
                        {item.durationMs !== undefined && <span>{Math.max(1, Math.round(item.durationMs / 1000))} s</span>}
                    </li>
                ))}
            </ul>
        </div>
    );
}
