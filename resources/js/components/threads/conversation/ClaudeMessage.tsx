import { cn } from '@geocodio/console-ui';
import type { ConversationItem } from './realtimeStub';

export function ClaudeMessage({ item }: { item: ConversationItem }) {
    return (
        <div className="flex gap-3" data-testid="thread-claude-message">
            <img src="/mascot-avatar.png" alt="" className="mt-0.5 h-6 w-6 shrink-0 rounded-pill" />
            <div className="min-w-0 flex-1">
                <div className="text-[12px] font-medium text-body">Claude</div>
                <p className={cn('mt-1 text-[13px] leading-relaxed whitespace-pre-wrap wrap-break-word', item.kind === 'thinking' && 'text-muted italic')}>{item.text}</p>
            </div>
        </div>
    );
}
