import { cn } from '@geocodio/console-ui';
import { MessageWithAttachments } from '@/components/attachments/Attachments';
import { Prose } from '@/components/Prose';
import type { MediaItem } from '@/types/tasks';
import type { ThreadMessage } from '@/types/threads';

export function PersonMessage({ message, pending, onOpenMedia }: { message: ThreadMessage; pending: boolean; onOpenMedia: (media: MediaItem[], index: number) => void }) {
    const time = new Date(message.sentAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

    return (
        <div
            className={cn('flex gap-3', pending && 'opacity-50')}
            data-testid={pending ? 'thread-message-pending' : 'thread-message'}
            title={pending ? 'Claude reads this as soon as it can.' : undefined}
        >
            <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-pill bg-panel-2 text-[11px] font-medium">{message.authorInitials}</div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-x-2 text-[12px]">
                    <span className="font-medium text-body">{message.authorName ?? 'Someone'}</span>
                    <span className="text-faint">{time}</span>
                    {message.model && <span className="rounded-pill bg-panel-2 px-2 py-0.5 text-[11px] text-muted">{message.model}</span>}
                </div>
                <MessageWithAttachments attachments={message.attachments} onOpenMedia={onOpenMedia}>
                    <Prose html={message.bodyHtml} className="mt-1" />
                </MessageWithAttachments>
            </div>
        </div>
    );
}
