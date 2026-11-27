import { Tooltip, cn } from '@geocodio/console-ui';
import { CircleStop, Info } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Prose } from '@/components/Prose';
import type { ConversationItem } from './conversationItem';
import { pickHtml } from './streamedHtml';

export function ClaudeMessage({ item, modelLabels = {} }: { item: ConversationItem; modelLabels?: Record<string, string> }) {
    // A row that mounts as streaming slides in once; the final item keeps its key, so the row stays mounted.
    const [entersStreaming] = useState(() => item.streaming === true);
    const lastStreamedHtml = useRef<string | null>(null);
    const streamedHtml = item.streaming === true && item.kind === 'text' ? (item.html ?? '') : null;
    useEffect(() => {
        if (streamedHtml !== null) {
            lastStreamedHtml.current = streamedHtml;
        }
    }, [streamedHtml]);
    const html = streamedHtml ?? (item.html ? pickHtml(lastStreamedHtml.current, item.html) : item.html);

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
                {/* A streaming text block always renders as Prose, so it never shows raw Markdown at a smaller size first. */}
                {html || streamedHtml !== null ? (
                    <Prose html={html ?? ''} className={cn('mt-1', item.kind === 'thinking' && 'text-muted italic')} />
                ) : (
                    <p className={cn('mt-1 text-[13px] leading-relaxed whitespace-pre-wrap wrap-break-word', item.kind === 'thinking' && 'text-muted italic')}>{item.text}</p>
                )}
                {item.cutOff && <CutOffMarker reason={item.cutReason} />}
            </div>
        </div>
    );
}

const RESTARTED_HINT = 'Claude’s session restarted before this reply finished. Ask Claude to continue if you need the rest.';

function CutOffMarker({ reason }: { reason?: ConversationItem['cutReason'] }) {
    if (reason === 'stopped') {
        return (
            <div className="mt-2 flex items-center gap-1.5 text-[12px] text-faint" data-testid="thread-cut-marker">
                <CircleStop className="size-3" aria-hidden />
                You stopped this reply
            </div>
        );
    }

    return (
        <Tooltip label={RESTARTED_HINT} side="bottom" className="max-w-[260px]">
            <div className="mt-2 flex w-fit items-center gap-1.5 text-[12px] text-faint" tabIndex={0} data-testid="thread-cut-marker">
                <CircleStop className="size-3" aria-hidden />
                Reply cut off
                <Info className="size-3" aria-hidden />
            </div>
        </Tooltip>
    );
}
