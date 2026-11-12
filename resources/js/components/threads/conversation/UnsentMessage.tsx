import { usePage } from '@inertiajs/react';
import { Spinner, cn } from '@geocodio/console-ui';
import type { ReactNode } from 'react';
import type { UnsentMessage as UnsentMessageData } from '@/threads/outbox/outbox';
import type { SharedProps } from '@/types/shared';

function Action({ onClick, testId, children }: { onClick: () => void; testId: string; children: ReactNode }) {
    return (
        <button type="button" onClick={onClick} data-testid={testId} className="cursor-pointer font-medium text-accent-text hover:underline">
            {children}
        </button>
    );
}

/** The person's own message between Enter and the server's confirmation, with what is happening to it. */
export function UnsentMessage({ message, onRetry, onEdit }: { message: UnsentMessageData; onRetry: () => void; onEdit: () => void }) {
    const user = usePage<SharedProps>().props.auth.user;
    const { status } = message;

    return (
        <div className="flex gap-3" data-testid="thread-message-unsent" data-status={status}>
            <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-pill bg-panel-2 text-[11px] font-medium text-muted">{user?.initials}</div>
            <div className="min-w-0 flex-1">
                <div className="text-[12px] font-medium text-muted">{user?.name ?? 'You'}</div>
                <p className="mt-1 whitespace-pre-wrap break-words text-muted">{message.text}</p>
                <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] text-muted" role="status" data-testid="thread-message-unsent-status">
                    {(status === 'queued' || status === 'sending' || status === 'slow') && <Spinner size="sm" />}
                    {(status === 'waiting' || status === 'refused') && <span aria-hidden className={cn('size-[7px] shrink-0 rounded-pill', status === 'refused' ? 'bg-fail' : 'bg-faint')} />}
                    {(status === 'queued' || status === 'sending') && <span>Sending…</span>}
                    {status === 'slow' && <span>Still sending. The connection is slow.</span>}
                    {status === 'waiting' && <span>Waiting for a connection. Yak sends it when the link is back.</span>}
                    {status === 'refused' && <span>Not sent. {message.reason}</span>}
                    {status === 'refused' && <Action onClick={onRetry} testId="thread-message-unsent-retry">Retry</Action>}
                    {status === 'refused' && <Action onClick={onEdit} testId="thread-message-unsent-edit">Edit</Action>}
                    {(status === 'slow' || status === 'waiting') && <Action onClick={onEdit} testId="thread-message-unsent-edit">Cancel and edit</Action>}
                </div>
            </div>
        </div>
    );
}
