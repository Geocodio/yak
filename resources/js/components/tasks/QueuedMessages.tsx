import { router } from '@inertiajs/react';
import { cn, IconButton, Tooltip } from '@geocodio/console-ui';
import { ListEnd, Navigation, Paperclip, Pencil, X } from 'lucide-react';
import { destroy as destroyQueued, update as updateQueued } from '@/routes/tasks/queued-messages';
import type { QueuedMessage, SteeringMode } from '@/types/tasks';

export const STEERING_MODES: { mode: SteeringMode; label: string; waitingLabel: string; icon: typeof ListEnd; hint: string }[] = [
    { mode: 'queue', label: 'Queue', waitingLabel: 'Queued', icon: ListEnd, hint: 'Give Yak a follow-up task after the current work is done.' },
    { mode: 'steer', label: 'Steer', waitingLabel: 'Steering', icon: Navigation, hint: 'Adjust the way Yak is doing its current task.' },
];

/** The most recently sent waiting message, which the up arrow pulls back to edit. */
export function latestQueued(messages: QueuedMessage[]): QueuedMessage | undefined {
    return messages.reduce<QueuedMessage | undefined>((newest, message) => (newest && newest.id > message.id ? newest : message), undefined);
}

const SOURCE_LABELS: Record<string, string> = { slack: 'Slack', linear: 'Linear', github_review: 'GitHub' };

/**
 * Messages waiting for a busy task, pinned above the composer's text box.
 * Each can switch between queued and steered, go back into the composer
 * to edit (`onEdit`), or be dropped.
 */
export function QueuedMessages({
    taskId,
    messages,
    canEdit,
    editingId,
    onEdit,
}: {
    taskId: number;
    messages: QueuedMessage[];
    canEdit: boolean;
    /** The message being pulled back into the composer, while that is in flight. */
    editingId: number | null;
    onEdit: (message: QueuedMessage) => void;
}) {
    if (messages.length === 0) {
        return null;
    }

    const setMode = (message: QueuedMessage, mode: SteeringMode) =>
        router.patch(updateQueued.url({ task: taskId, message: message.id }), { mode }, { preserveScroll: true, preserveState: true });

    // The up arrow edits the most recently sent message, wherever it sits.
    const latestId = latestQueued(messages)?.id;

    const remove = (message: QueuedMessage) =>
        router.delete(destroyQueued.url({ task: taskId, message: message.id }), { preserveScroll: true, preserveState: true });

    return (
        <ul className="flex flex-col divide-y divide-hair rounded-t-card border-b border-hair bg-panel-2" aria-label="Waiting messages" data-testid="queued-messages">
            {messages.map((message) => {
                const current = STEERING_MODES.find((option) => option.mode === message.mode) ?? STEERING_MODES[0];
                const other = STEERING_MODES.find((option) => option.mode !== message.mode) ?? STEERING_MODES[1];
                const Icon = current.icon;
                const source = SOURCE_LABELS[message.source];

                return (
                    <li
                        key={message.id}
                        className={cn('flex min-w-0 items-center gap-2 py-1 pr-1.5 pl-2.5 text-[12.5px]', editingId === message.id && 'opacity-50')}
                        data-testid={`queued-message-${message.id}`}
                    >
                        <Tooltip
                            label={
                                message.canSteer
                                    ? `${current.hint} Click to ${other.label.toLowerCase()} instead.`
                                    : `${current.hint} A GitHub review waits for the follow-up, so the reviewer is asked to look again.`
                            }
                        >
                            <button
                                type="button"
                                onClick={() => message.canSteer && setMode(message, other.mode)}
                                aria-disabled={!message.canSteer}
                                className={cn(
                                    'inline-flex h-5 shrink-0 items-center gap-1 rounded-pill px-2 text-[11px] font-medium transition-colors',
                                    message.mode === 'steer' ? 'bg-accent-soft text-body' : 'bg-panel text-muted',
                                    message.canSteer ? 'hover:text-body' : 'cursor-default',
                                )}
                                data-testid={`queued-message-mode-${message.id}`}
                                data-mode={message.mode}
                            >
                                <Icon size={11} aria-hidden="true" />
                                {current.waitingLabel}
                            </button>
                        </Tooltip>
                        <span className="min-w-0 flex-1 truncate text-body" title={message.text}>
                            {message.text.split('\n')[0]}
                        </span>
                        {source && <span className="shrink-0 text-[11px] text-faint">via {source}</span>}
                        {message.attachments.length > 0 && (
                            <span className="inline-flex shrink-0 items-center gap-0.5 text-[11px] text-faint" aria-label={`${message.attachments.length} attached`}>
                                <Paperclip size={11} aria-hidden="true" />
                                {message.attachments.length}
                            </span>
                        )}
                        {canEdit && (
                            <IconButton
                                label={message.id === latestId ? 'Edit message (↑)' : 'Edit message'}
                                onClick={() => onEdit(message)}
                                disabled={editingId !== null}
                                className="h-6 w-6 shrink-0 border-0 bg-transparent shadow-none"
                                data-testid={`queued-message-edit-${message.id}`}
                            >
                                <Pencil size={12} />
                            </IconButton>
                        )}
                        <IconButton
                            label="Remove from queue"
                            onClick={() => remove(message)}
                            disabled={editingId === message.id}
                            className="h-6 w-6 shrink-0 border-0 bg-transparent shadow-none"
                            data-testid={`queued-message-remove-${message.id}`}
                        >
                            <X size={13} />
                        </IconButton>
                    </li>
                );
            })}
        </ul>
    );
}
