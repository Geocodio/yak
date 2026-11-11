import { cn } from '@geocodio/console-ui';
import { Prose } from '@/components/Prose';
import type { ConversationItem } from './conversationItem';

export function ClaudeMessage({ item }: { item: ConversationItem }) {
    return (
        <div className="flex gap-3" data-testid="thread-claude-message">
            <img src="/mascot-avatar.png" alt="" className="mt-0.5 h-6 w-6 shrink-0 rounded-pill" />
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 text-[12px] font-medium text-body">
                    Claude
                    {item.model && (
                        <span className="rounded-pill bg-panel-2 px-1.5 py-px font-mono text-[10px] font-normal text-muted" data-testid="thread-model-chip">
                            {item.model}
                        </span>
                    )}
                </div>
                {item.html ? (
                    <Prose html={item.html} className={cn('mt-1', item.kind === 'thinking' && 'text-muted italic')} />
                ) : (
                    <p className={cn('mt-1 text-[13px] leading-relaxed whitespace-pre-wrap wrap-break-word', item.kind === 'thinking' && 'text-muted italic')}>{item.text}</p>
                )}
            </div>
        </div>
    );
}
