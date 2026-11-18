import { router, useForm } from '@inertiajs/react';
import { Button, Menu } from '@geocodio/console-ui';
import { Layers, Plus } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { focusAfterPaletteCloses } from '@/commandPalette/focusAfterPaletteCloses';
import { THREAD_GROUP, composerActions } from '@/commandPalette/threadPageActions';
import { useCommandPaletteActions } from '@/commandPalette/useCommandPaletteActions';
import { canContinueTurn } from '@/components/threads/conversation/GuardStopCard';
import { QueuedMessages, latestEditable, type QueuedRow } from '@/components/shared/QueuedMessages';
import { AttachButton, AttachmentDropOverlay, DraftAttachments } from '@/components/attachments/Attachments';
import { AttachmentTextarea, type AttachmentTextareaHandle } from '@/components/attachments/AttachmentTextarea';
import { useAttachmentDraft } from '@/components/attachments/useAttachmentDraft';
import { outbox } from '@/threads/outbox/useOutbox';
import { stop } from '@/routes/threads';
import { destroy as destroyQueued, update as updateQueued } from '@/routes/threads/queued-messages';
import type { TeleportData, ThreadComposer as ThreadComposerData, ThreadData, ThreadNotice, ThreadRequest } from '@/types/threads';
import { channelHint, composerCopy } from './composerCopy';
import { ModelPicker } from '@/components/accounts/ModelPicker';
import { ContextRing } from './ContextRing';

const DISABLED_COPY: Partial<Record<ThreadComposerData['state'], string>> = {
    disabled_closed: 'This thread is closed.',
    disabled_computer: 'Your computer is working on this thread',
    disabled_blocked: 'Sending is paused until the problem above is fixed.',
    readonly: 'You can read this thread but not send messages.',
};

const MAX_MESSAGE_LENGTH = 20000;

export function ThreadComposer({ queued = [], thread, composer, notice, teleportState, isOffline = false, restoredDraft = null, request = null, onAddSubThread }: { queued?: QueuedRow[]; request?: ThreadRequest | null; thread: ThreadData; composer: ThreadComposerData; notice: ThreadNotice | null; teleportState: TeleportData['state']; isOffline?: boolean; restoredDraft?: { text: string } | null; onAddSubThread?: () => void }) {
    const form = useForm<{ message: string; model: string | null; effort: string | null; attachments: File[]; attachment_refs: string[] }>({
        message: '',
        model: composer.model,
        effort: composer.effort,
        attachments: [],
        attachment_refs: [],
    });
    const [editingQueuedId, setEditingQueuedId] = useState<number | null>(null);
    const editorRef = useRef<AttachmentTextareaHandle>(null);
    const inputRef = useRef<HTMLTextAreaElement>(null);
    const disabled = composer.state === 'disabled_closed' || composer.state === 'disabled_computer' || composer.state === 'disabled_blocked' || composer.state === 'readonly';
    const working = composer.state === 'working';
    const isOwnerless = request !== null && request.starter.key === 'yak' && request.responsibleName === null && !thread.isOwner;
    const copy = composerCopy(notice, composer.state, teleportState, isOwnerless);
    const hint = DISABLED_COPY[composer.state] ?? (copy.isDefaultHint && request !== null ? channelHint(request.source, isOwnerless) : null) ?? copy.hint;
    const placeholder = isOwnerless && copy.placeholder === 'Message Claude' ? 'Message Claude to take over this thread' : copy.placeholder;
    const draft = useAttachmentDraft({
        enabled: !disabled,
        firstNumber: composer.nextAttachmentNumber,
        onAdd: (added) => editorRef.current?.insertTokens(added),
    });
    const canContinue = notice !== null && canContinueTurn(notice, composer.state === 'readonly');
    const actions = useMemo(
        () =>
            composerActions({
                threadId: thread.id,
                isWorking: working,
                canStop: !disabled,
                canContinue,
                canFocus: !disabled,
                focusComposer: () => focusAfterPaletteCloses(inputRef.current),
            }),
        [thread.id, working, disabled, canContinue],
    );
    useCommandPaletteActions(THREAD_GROUP, actions);
    const errors = form.errors as Record<string, string | undefined>;
    const empty = form.data.message.trim() === '';

    // A message taken back from the conversation returns to the box, ahead of anything typed since.
    useEffect(() => {
        if (restoredDraft !== null) {
            form.setData('message', [restoredDraft.text, form.data.message].filter((part) => part.trim() !== '').join('\n\n'));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [restoredDraft]);

    // A message Yak took in or someone removed no longer has its editor to close.
    useEffect(() => {
        if (editingQueuedId !== null && !queued.some((message) => message.id === editingQueuedId)) {
            setEditingQueuedId(null);
        }
    }, [queued, editingQueuedId]);
    const canManageQueued = composer.state !== 'readonly' && composer.state !== 'disabled_closed' && composer.state !== 'legacy';

    const submit = () => {
        if (disabled || form.processing || empty) {
            return;
        }

        // Every message goes through the send queue: it shows at once, with its images, and survives a bad link.
        if (form.data.message.length > MAX_MESSAGE_LENGTH) {
            form.setError('message', 'The message must not be longer than 20,000 characters.');

            return;
        }

        outbox.enqueue(thread.id, form.data.message, form.data.model, form.data.effort, draft.files, draft.refs);
        form.clearErrors();
        form.setData('message', '');
        draft.clear();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        // Up in an empty box edits the newest waiting message, as it does in the task composer.
        if (event.key === 'ArrowUp' && !event.shiftKey && !event.altKey && !event.metaKey && !event.ctrlKey && !event.nativeEvent.isComposing && form.data.message === '' && draft.files.length === 0 && draft.refs.length === 0 && canManageQueued) {
            const latest = latestEditable(queued);

            if (latest !== undefined) {
                event.preventDefault();
                setEditingQueuedId(latest.id);

                return;
            }
        }

        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            submit();
        }
    };

    return (
        <div className="shrink-0 border-t border-hair bg-app px-4 py-4 sm:px-8" data-testid="thread-composer">
            <div className="mx-auto max-w-[820px]">
                <div className="@container relative rounded-card border border-hair bg-panel shadow-card focus-within:border-accent" {...draft.dropzoneProps}>
                    <AttachmentDropOverlay draft={draft} />
                    <QueuedMessages
                        messages={queued}
                        updateUrl={(message) => updateQueued.url({ thread: thread.id, message: message.id })}
                        removeUrl={(message) => destroyQueued.url({ thread: thread.id, message: message.id })}
                        canEdit={canManageQueued}
                        canSteerNow={working}
                        onEdit={(message) => setEditingQueuedId(message.id)}
                        inlineEditingId={editingQueuedId}
                        onInlineEditEnd={() => setEditingQueuedId(null)}
                        testId="thread-queued-messages"
                        className="max-h-40 overflow-y-auto"
                    />
                    <AttachmentTextarea
                        ref={editorRef}
                        inputRef={inputRef}
                        draft={draft}
                        rows={2}
                        placeholder={isOffline && !disabled ? 'Add a message. It is sent when the connection is back.' : placeholder}
                        value={form.data.message}
                        onValueChange={(value) => form.setData('message', value)}
                        onKeyDown={onKeyDown}
                        onPaste={draft.onPaste}
                        disabled={disabled}
                        aria-invalid={errors.message ? true : undefined}
                        className="w-full resize-none border-0 shadow-none focus:ring-0"
                        data-testid="thread-composer-input"
                    />
                    <DraftAttachments draft={draft} errors={errors} progress={null} />
                    {(errors.message || errors.model) && (
                        <p role="alert" data-testid="thread-composer-error" className="px-3 pb-2 text-[12px] text-fail">
                            {errors.message ?? errors.model}
                        </p>
                    )}
                    <div className="flex items-center justify-between gap-3 border-t border-hair px-3 py-2">
                        <div className="flex min-w-0 items-center gap-3">
                            {!disabled && onAddSubThread !== undefined && (
                                <Menu
                                    trigger={<Plus size={14} aria-hidden />}
                                    aria-label="Add"
                                    items={[{ key: 'sub-thread', label: 'Add a sub-thread', icon: <Layers size={14} />, onSelect: onAddSubThread, testId: 'composer-add-sub-thread' }]}
                                    className="size-7 justify-center rounded-[6px] p-0 text-muted hover:bg-panel-2 hover:text-body"
                                    data-testid="composer-plus-menu"
                                />
                            )}
                            {!disabled && <AttachButton draft={draft} disabled={form.processing} />}
                            <ModelPicker variant="inline" models={composer.models} value={form.data.model} onChange={(model) => form.setData('model', model)} effort={form.data.effort} onEffortChange={(effort) => form.setData('effort', effort)} disabled={disabled} testId="composer-model" />
                            <ContextRing composer={composer} model={form.data.model} />
                            <span className="truncate text-[11px] text-faint" data-testid="thread-composer-hint">
                                {hint}
                            </span>
                        </div>
                        <div className="flex shrink-0 gap-2">
                            {working && (
                                <Button variant="secondary" className="h-7" onClick={() => router.post(stop.url(thread.id), {}, { preserveScroll: true })} data-testid="thread-stop">
                                    Stop
                                </Button>
                            )}
                            <Button
                                variant="primary"
                                className="h-7"
                                onClick={submit}
                                pending={form.processing}
                                disabled={disabled || empty}
                                data-testid="thread-send"
                            >
                                Send
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
