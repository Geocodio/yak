import { router } from '@inertiajs/react';
import { Button, cn, IconButton, Textarea, toast, Tooltip } from '@geocodio/console-ui';
import { ListEnd, Navigation, Paperclip, Pencil, X } from 'lucide-react';
import { useEffect, useState, type KeyboardEvent } from 'react';
import type { QueuedMessage, SteeringMode } from '@/types/tasks';

/** A waiting message; `canEditText` is false when someone else wrote it, and absent where every message can be edited. */
export type QueuedRow = QueuedMessage & { canEditText?: boolean };

export const STEERING_MODES: { mode: SteeringMode; label: string; waitingLabel: string; icon: typeof ListEnd; hint: string }[] = [
    { mode: 'queue', label: 'Queue', waitingLabel: 'Queued', icon: ListEnd, hint: 'Give Yak a follow-up task after the current work is done.' },
    { mode: 'steer', label: 'Steer', waitingLabel: 'Steering', icon: Navigation, hint: 'Adjust the way Yak is doing its current task.' },
];

/** The most recently sent waiting message, which the up arrow pulls back to edit. */
export function latestQueued(messages: QueuedMessage[]): QueuedMessage | undefined {
    return messages.reduce<QueuedMessage | undefined>((newest, message) => (newest && newest.id > message.id ? newest : message), undefined);
}

const SOURCE_LABELS: Record<string, string> = { slack: 'Slack', linear: 'Linear', github_review: 'GitHub' };

const isEditable = (message: QueuedRow) => message.canEditText !== false;

/** The most recently sent waiting message whose text the person may edit, which the up arrow edits. */
export function latestEditable(messages: QueuedRow[]): QueuedRow | undefined {
    return latestQueued(messages.filter(isEditable));
}

/**
 * Messages waiting for a busy conversation, pinned above the composer's text box.
 * Each can switch between queued and steered, be edited, or be dropped. The routes
 * come in as props. Editing either hands the message to `onEdit` (the task composer
 * takes it back into its text box) or, when `inlineEditingId` names it, happens in the row.
 */
export function QueuedMessages({
    messages,
    updateUrl,
    removeUrl,
    canEdit,
    canSteerNow = true,
    editingId = null,
    onEdit,
    inlineEditingId = null,
    onInlineEditEnd,
    testId = 'queued-messages',
    className,
}: {
    messages: QueuedRow[];
    updateUrl: (message: QueuedRow) => string;
    removeUrl: (message: QueuedRow) => string;
    /** False for someone who cannot drive: the rows are read-only. */
    canEdit: boolean;
    /** Steering reaches only a running turn, so a waiting message cannot switch to it otherwise. */
    canSteerNow?: boolean;
    /** The message being pulled back into the composer, while that is in flight. */
    editingId?: number | null;
    onEdit: (message: QueuedRow) => void;
    /** The message whose text is open for editing in its row. */
    inlineEditingId?: number | null;
    onInlineEditEnd?: () => void;
    testId?: string;
    className?: string;
}) {
    const [busyId, setBusyId] = useState<number | null>(null);
    const [draft, setDraft] = useState('');

    // The draft starts from the message's text each time a row opens, whether its pencil or the up arrow opened it.
    useEffect(() => {
        setDraft(messages.find((message) => message.id === inlineEditingId)?.text ?? '');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [inlineEditingId]);

    if (messages.length === 0) {
        return null;
    }

    const send = (message: QueuedRow, method: 'patch' | 'delete', data: { mode?: SteeringMode; text?: string } = {}, onDone?: () => void) => {
        if (busyId !== null) {
            return;
        }
        setBusyId(message.id);
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onDone,
            onError: (errors: Record<string, string>) => toast.error(Object.values(errors)[0] ?? 'Could not change that message.'),
            onFinish: () => setBusyId(null),
        };

        if (method === 'patch') {
            router.patch(updateUrl(message), data, options);
        } else {
            router.delete(removeUrl(message), options);
        }
    };

    const setMode = (message: QueuedRow, mode: SteeringMode) => send(message, 'patch', { mode });
    const remove = (message: QueuedRow) => send(message, 'delete');
    const save = (message: QueuedRow) => {
        const text = draft.trim();

        if (text === '' || text === message.text) {
            onInlineEditEnd?.();

            return;
        }
        send(message, 'patch', { text }, onInlineEditEnd);
    };
    const onEditorKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>, message: QueuedRow) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            onInlineEditEnd?.();
        } else if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            save(message);
        }
    };

    // The up arrow edits the most recently sent message, wherever it sits.
    const latestId = latestEditable(messages)?.id;

    return (
        <ul
            className={cn('flex flex-col divide-y divide-hair rounded-t-card border-b border-hair bg-panel-2', className)}
            aria-label="Waiting messages"
            data-testid={testId}
        >
            {messages.map((message) => {
                const current = STEERING_MODES.find((option) => option.mode === message.mode) ?? STEERING_MODES[0];
                const other = STEERING_MODES.find((option) => option.mode !== message.mode) ?? STEERING_MODES[1];
                const Icon = current.icon;
                const source = SOURCE_LABELS[message.source];
                const isBusy = busyId === message.id;
                const isEditingInline = inlineEditingId === message.id;
                const canSwitch = canEdit && message.canSteer && (other.mode !== 'steer' || canSteerNow);

                return (
                    <li
                        key={message.id}
                        className={cn('flex min-w-0 gap-2 py-1 pr-1.5 pl-2.5 text-[12.5px]', isEditingInline ? 'flex-col items-stretch' : 'items-center', (editingId === message.id || isBusy) && 'opacity-50')}
                        data-testid={`queued-message-${message.id}`}
                    >
                        {isEditingInline ? (
                            <>
                                <Textarea
                                    autoFocus
                                    rows={2}
                                    value={draft}
                                    maxLength={20000}
                                    disabled={isBusy}
                                    onChange={(event) => setDraft(event.target.value)}
                                    onKeyDown={(event) => onEditorKeyDown(event, message)}
                                    aria-label="Edit message"
                                    data-testid={`queued-message-input-${message.id}`}
                                />
                                <div className="flex justify-end gap-2 pb-1">
                                    <Button variant="secondary" className="h-7" onClick={() => onInlineEditEnd?.()} disabled={isBusy} data-testid={`queued-message-cancel-${message.id}`}>
                                        Cancel
                                    </Button>
                                    <Button variant="primary" className="h-7" onClick={() => save(message)} pending={isBusy} disabled={draft.trim() === ''} data-testid={`queued-message-save-${message.id}`}>
                                        Save
                                    </Button>
                                </div>
                            </>
                        ) : (
                            <>
                                <Tooltip
                                    label={
                                        !message.canSteer
                                            ? `${current.hint} A GitHub review waits for the follow-up, so the reviewer is asked to look again.`
                                            : canSwitch
                                              ? `${current.hint} Click to ${other.label.toLowerCase()} instead.`
                                              : canEdit
                                                ? `${current.hint} Steering needs Yak to be working.`
                                                : current.hint
                                    }
                                >
                                    <button
                                        type="button"
                                        onClick={() => canSwitch && !isBusy && setMode(message, other.mode)}
                                        aria-disabled={!canSwitch || isBusy}
                                        className={cn(
                                            'inline-flex h-5 shrink-0 items-center gap-1 rounded-pill px-2 text-[11px] font-medium transition-colors',
                                            message.mode === 'steer' ? 'bg-accent-soft text-body' : 'bg-panel text-muted',
                                            canSwitch ? 'hover:text-body' : 'cursor-default',
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
                                {canEdit && isEditable(message) && (
                                    <IconButton
                                        label={message.id === latestId ? 'Edit message (↑)' : 'Edit message'}
                                        onClick={() => onEdit(message)}
                                        disabled={editingId !== null || isBusy || inlineEditingId !== null}
                                        className="h-6 w-6 shrink-0 border-0 bg-transparent shadow-none"
                                        data-testid={`queued-message-edit-${message.id}`}
                                    >
                                        <Pencil size={12} />
                                    </IconButton>
                                )}
                                {canEdit && (
                                    <IconButton
                                        label="Remove from queue"
                                        onClick={() => remove(message)}
                                        disabled={editingId === message.id || isBusy}
                                        className="h-6 w-6 shrink-0 border-0 bg-transparent shadow-none"
                                        data-testid={`queued-message-remove-${message.id}`}
                                    >
                                        <X size={13} />
                                    </IconButton>
                                )}
                            </>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
