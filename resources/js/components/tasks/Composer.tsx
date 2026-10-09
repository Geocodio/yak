import { router, useForm } from '@inertiajs/react';
import { Button, cn, Menu, toast } from '@geocodio/console-ui';
import { ChevronUp } from 'lucide-react';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { AttachButton, AttachmentDropOverlay, DraftAttachments } from '@/components/attachments/Attachments';
import { AttachmentTextarea, type AttachmentTextareaHandle } from '@/components/attachments/AttachmentTextarea';
import { findTokens, tokenText } from '@/components/attachments/attachmentTokens';
import { useAttachmentDraft } from '@/components/attachments/useAttachmentDraft';
import { latestQueued, QueuedMessages, STEERING_MODES } from '@/components/tasks/QueuedMessages';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { store as storeMessage } from '@/routes/tasks/messages';
import { destroy as destroyQueued } from '@/routes/tasks/queued-messages';
import type { SharedProps } from '@/types/shared';
import type { ComposerData, MediaItem, QueuedMessage, SteeringMode } from '@/types/tasks';

export function Composer({
    taskId,
    composer,
    fillValue,
    onOpenMedia,
}: {
    taskId: number;
    composer: ComposerData;
    fillValue: string | null;
    onOpenMedia: (media: MediaItem[], index: number) => void;
}) {
    const form = useForm<{ message: string; attachments: File[]; attachment_refs: string[]; mode: SteeringMode | null }>({
        message: '',
        attachments: [],
        attachment_refs: [],
        mode: null,
    });
    const disabled = composer.state === 'disabled_failed' || composer.state === 'disabled_closed';
    const isSteering = composer.state === 'steering';
    const [mode, setMode] = useState<SteeringMode>('queue');
    const [editingId, setEditingId] = useState<number | null>(null);
    const editorRef = useRef<AttachmentTextareaHandle>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const draft = useAttachmentDraft({
        enabled: !disabled,
        firstNumber: composer.nextAttachmentNumber,
        onAdd: (added) => editorRef.current?.insertTokens(added),
    });
    const [expanded, setExpanded] = useState(false);
    const isDesktop = useMediaQuery('(min-width: 1024px)');
    const errors = form.errors as Record<string, string | undefined>;
    const hasAttachments = draft.items.length > 0;

    useEffect(() => {
        if (fillValue !== null) {
            // The option replaces the text, but files already placed in it
            // keep their tokens (after the option) rather than losing them.
            const tokens = findTokens(form.data.message, new Set(draft.refs)).map((token) => tokenText(token.ref));
            form.setData('message', [fillValue, ...tokens].join(' '));
            // A clarification option fills the message without the textarea
            // ever being focused, so the note-and-button row must expand on
            // its own or the phone Send button stays hidden.
            setExpanded(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fillValue]);

    // Server errors are keyed by position (`attachments.2`), so they stop
    // lining up as soon as the list changes.
    useEffect(() => {
        const stale = Object.keys(form.errors).filter((key) => key.startsWith('attachments')) as (keyof typeof form.data)[];
        if (stale.length > 0) {
            form.clearErrors(...stale);
        }
        if (hasAttachments) {
            setExpanded(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [draft.items]);

    const submit = () => {
        if (disabled || form.processing || form.data.message.trim() === '') {
            return;
        }
        form.transform((data) => ({ ...data, attachments: draft.files, attachment_refs: draft.refs, mode: isSteering ? mode : null }));
        form.post(storeMessage.url(taskId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                draft.clear();
            },
        });
    };

    /**
     * Take a waiting message off the queue and back into the text box, files
     * included, ahead of anything already typed. The files are fetched first
     * because withdrawing the message deletes them, and nothing is filled in
     * if Yak got to the message before the withdrawal landed.
     */
    const editQueued = async (message: QueuedMessage) => {
        if (editingId !== null || disabled) {
            return;
        }
        setEditingId(message.id);

        let files: { file: File; ref: string | null }[];
        try {
            files = await Promise.all(
                message.attachments.map(async (attachment) => {
                    const response = await fetch(attachment.url);
                    if (!response.ok) {
                        throw new Error(`Could not fetch ${attachment.name}`);
                    }

                    return { file: new File([await response.blob()], attachment.name, { type: attachment.mimeType }), ref: attachment.reference };
                }),
            );
        } catch {
            toast.error('Could not load the files on that message, so it stays queued.');
            setEditingId(null);
            return;
        }

        router.delete(destroyQueued.url({ task: taskId, message: message.id }), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if ((page.props as unknown as SharedProps).flash.error) {
                    return;
                }
                // Read what is typed now, not when the edit began: the files and
                // the withdrawal can take a while, and typing carries on meanwhile.
                form.setData((data) => {
                    const typed = data.message.trim();

                    return { ...data, message: typed === '' ? message.text : `${message.text}\n\n${typed}` };
                });
                draft.adopt(files.filter((item): item is { file: File; ref: string } => item.ref !== null));
                draft.add(files.filter((item) => item.ref === null).map((item) => item.file));
                setMode(message.mode);
                setExpanded(true);
                requestAnimationFrame(() => {
                    textareaRef.current?.focus();
                    textareaRef.current?.setSelectionRange(message.text.length, message.text.length);
                });
            },
            onFinish: () => setEditingId(null),
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            event.preventDefault();
            submit();
            return;
        }

        // Up with the caret at the very start, where it would do nothing,
        // pulls the most recently sent waiting message back in to edit.
        if (event.key !== 'ArrowUp' || event.shiftKey || event.altKey || event.metaKey || event.ctrlKey) {
            return;
        }
        const caretAtStart = event.currentTarget.selectionStart === 0 && event.currentTarget.selectionEnd === 0;
        const latest = latestQueued(composer.queued);
        if (caretAtStart && latest) {
            event.preventDefault();
            editQueued(latest);
        }
    };

    const uploading = form.processing && hasAttachments;
    const modeOption = STEERING_MODES.find((option) => option.mode === mode) ?? STEERING_MODES[0];
    const note = hasAttachments && form.data.message.trim() === '' ? 'Add a message to send with your files.' : isSteering ? modeOption.hint : composer.note;
    const buttonLabel = isSteering ? modeOption.label : composer.buttonLabel;

    return (
        <div className="shrink-0 border-t border-hair bg-app px-4 py-4 sm:px-8" data-testid="composer">
            <div className="mx-auto max-w-[820px]">
                <div
                    className="relative rounded-card border border-hair bg-panel shadow-card focus-within:border-accent"
                    {...draft.dropzoneProps}
                >
                    <AttachmentDropOverlay draft={draft} />
                    <QueuedMessages taskId={taskId} messages={composer.queued} canEdit={!disabled} editingId={editingId} onEdit={editQueued} />
                    <AttachmentTextarea
                        ref={editorRef}
                        inputRef={textareaRef}
                        draft={draft}
                        rows={isDesktop ? 2 : expanded || form.data.message !== '' ? 3 : 1}
                        placeholder={composer.placeholder}
                        value={form.data.message}
                        onValueChange={(value) => form.setData('message', value)}
                        onKeyDown={onKeyDown}
                        onPaste={draft.onPaste}
                        onFocus={() => setExpanded(true)}
                        onBlur={() => form.data.message.trim() === '' && !hasAttachments && setExpanded(false)}
                        disabled={disabled}
                        className={cn(
                            'w-full resize-none border-0 shadow-none focus:ring-0',
                            !isDesktop && !expanded && form.data.message === '' && 'min-h-0',
                        )}
                        data-testid="composer-input"
                    />
                    <DraftAttachments
                        draft={draft}
                        errors={errors}
                        progress={uploading ? (form.progress?.percentage ?? 0) : null}
                        onOpenMedia={onOpenMedia}
                    />
                    {errors.message && <p className="px-3 pb-2 text-[12px] text-fail">{errors.message}</p>}
                    <div className={cn('flex items-center justify-between gap-3 border-t border-hair px-3 py-2', !expanded && 'max-lg:hidden')}>
                        <div className="flex min-w-0 items-center gap-2">
                            {!disabled && <AttachButton draft={draft} disabled={form.processing} />}
                            <span className="truncate text-[11px] text-faint">{note}</span>
                        </div>
                        {buttonLabel && (
                            <div className="flex shrink-0">
                                <Button
                                    variant="primary"
                                    className={cn('h-7 shrink-0', isSteering && 'rounded-r-none')}
                                    onClick={submit}
                                    pending={form.processing}
                                    pendingLabel={uploading ? 'Uploading…' : undefined}
                                    disabled={disabled || form.data.message.trim() === ''}
                                    data-testid="composer-submit"
                                >
                                    {buttonLabel} <span className="ml-1.5 hidden text-[10px] opacity-70 pointer-fine:inline">⌘↵</span>
                                </Button>
                                {isSteering && (
                                    <Menu
                                        trigger={<ChevronUp size={13} aria-hidden="true" />}
                                        aria-label="When Yak reads it"
                                        className="h-7 justify-center rounded-r-control border-l border-accent-ink/20 bg-accent px-1.5 text-accent-ink shadow-card hover:opacity-90"
                                        // Menu rows are a fixed single line; let these grow to fit the hint under each label.
                                        popupClassName="[&_[role^=menuitem]]:h-auto [&_[role^=menuitem]]:py-1.5"
                                        align="end"
                                        items={STEERING_MODES.map(({ mode: option, label, hint, icon: Icon }) => ({
                                            key: option,
                                            // Menu types labels as strings but renders whatever it is given.
                                            label: (
                                                <span className="flex flex-col">
                                                    <span>{label}</span>
                                                    <span className="text-[11.5px] text-muted">{hint}</span>
                                                </span>
                                            ) as unknown as string,
                                            icon: <Icon size={13} />,
                                            checked: mode === option,
                                            onSelect: () => setMode(option),
                                            testId: `composer-mode-${option}`,
                                        }))}
                                        data-testid="composer-mode"
                                    />
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
