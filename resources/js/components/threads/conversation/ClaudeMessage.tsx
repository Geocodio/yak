import { cn } from '@geocodio/console-ui';
import { useRef, useState } from 'react';
import { Prose } from '@/components/Prose';
import { renderStreamingMarkdown } from '@/threads/streaming/streamingMarkdown';
import type { ConversationItem } from './conversationItem';
import { pickHtml } from './streamedHtml';

export function ClaudeMessage({ item, modelLabels = {} }: { item: ConversationItem; modelLabels?: Record<string, string> }) {
    // A row that mounts as streaming slides in once; the final item keeps its key, so the row stays mounted.
    const [entersStreaming] = useState(() => item.streaming === true);
    const lastStreamedHtml = useRef<string | null>(null);
    let html = item.html;

    if (item.streaming === true && item.kind === 'text') {
        html = lastStreamedHtml.current = renderStreamingMarkdown(item.text ?? '');
    } else if (item.html) {
        html = pickHtml(lastStreamedHtml.current, item.html);
    }

    return (
        <div className={cn('flex gap-3', entersStreaming && 'row-enter')} data-testid="thread-claude-message" aria-busy={item.streaming === true ? true : undefined}>
            <img src="/mascot-avatar.png" alt="" className="mt-0.5 h-6 w-6 shrink-0 rounded-pill" />
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 text-[12px] font-medium text-body">
                    Claude
                    {item.at && <span className="font-normal text-faint">{new Date(item.at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}</span>}
                    {item.model && (
                        <span className="rounded-pill bg-panel-2 px-1.5 py-px font-mono text-[10px] font-normal text-muted" data-testid="thread-model-chip">
                            {modelLabels[item.model] ?? item.model}
                        </span>
                    )}
                </div>
                {html ? (
                    <Prose html={html} className={cn('mt-1', item.kind === 'thinking' && 'text-muted italic')} />
                ) : (
                    <p className={cn('mt-1 text-[13px] leading-relaxed whitespace-pre-wrap wrap-break-word', item.kind === 'thinking' && 'text-muted italic')}>{item.text}</p>
                )}
            </div>
        </div>
    );
}
