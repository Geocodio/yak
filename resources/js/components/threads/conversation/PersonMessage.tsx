import { Clock, Laptop } from 'lucide-react';
import { MessageWithAttachments } from '@/components/attachments/Attachments';
import { Prose } from '@/components/Prose';
import type { MediaItem } from '@/types/tasks';
import type { ThreadMessage } from '@/types/threads';

export function PersonMessage({ message, pending, onOpenMedia, origin, modelLabels = {} }: { origin?: 'local'; modelLabels?: Record<string, string>; message: ThreadMessage; pending: boolean; onOpenMedia: (media: MediaItem[], index: number) => void }) {
    const time = new Date(message.sentAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

    return (
        <div
            className="flex gap-3"
            data-testid={pending ? 'thread-message-pending' : 'thread-message'}
            title={pending ? 'Claude reads this as soon as it can.' : undefined}
        >
            <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-pill bg-panel-2 text-[11px] font-medium">{message.authorInitials}</div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-x-2 text-[12px]">
                    <span className="font-medium text-body">{message.authorName ?? 'Someone'}</span>
                    <span className="text-faint">{time}</span>
                    {origin === 'local' && (
                        <span className="inline-flex items-center gap-1 rounded-pill bg-panel-2 py-px pr-2 pl-1.5 text-[11px] text-muted" title="This turn ran on your computer" data-testid="thread-message-origin-local">
                            <Laptop className="size-[11px]" aria-hidden />
                            Your computer
                        </span>
                    )}
                    {pending && (
                        <span className="inline-flex items-center gap-1 text-faint" data-testid="thread-message-not-read">
                            <Clock className="size-3" aria-hidden />
                            Not read yet
                        </span>
                    )}
                    {message.model && <span className="rounded-pill bg-panel-2 px-2 py-0.5 text-[11px] text-muted">{modelLabels[message.model] ?? message.model}</span>}
                </div>
                <MessageWithAttachments attachments={message.attachments} onOpenMedia={onOpenMedia}>
                    <Prose html={message.bodyHtml} className="mt-1" />
                </MessageWithAttachments>
                {message.intent !== null && (
                    <p className="mt-1 text-[12px] text-faint" data-testid="thread-message-intent">
                        {message.intent === 'add_preview_manifest' ? 'Sent from the Preview panel: set up the preview' : 'Sent from the Preview panel: fix the preview'}
                    </p>
                )}
            </div>
        </div>
    );
}
