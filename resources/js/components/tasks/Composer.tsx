import { useForm } from '@inertiajs/react';
import { Button, cn } from '@geocodio/console-ui';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { AttachButton, AttachmentDropOverlay, DraftAttachments } from '@/components/attachments/Attachments';
import { AttachmentTextarea, type AttachmentTextareaHandle } from '@/components/attachments/AttachmentTextarea';
import { findTokens, tokenText } from '@/components/attachments/attachmentTokens';
import { useAttachmentDraft } from '@/components/attachments/useAttachmentDraft';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { store as storeMessage } from '@/routes/tasks/messages';
import type { ComposerData, MediaItem } from '@/types/tasks';

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
    const form = useForm<{ message: string; attachments: File[]; attachment_refs: string[] }>({ message: '', attachments: [], attachment_refs: [] });
    const disabled = composer.state === 'disabled_failed' || composer.state === 'disabled_closed';
    const editorRef = useRef<AttachmentTextareaHandle>(null);
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
        form.transform((data) => ({ ...data, attachments: draft.files, attachment_refs: draft.refs }));
        form.post(storeMessage.url(taskId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                draft.clear();
            },
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            event.preventDefault();
            submit();
        }
    };

    const uploading = form.processing && hasAttachments;
    const note = hasAttachments && form.data.message.trim() === '' ? 'Add a message to send with your files.' : composer.note;

    return (
        <div className="shrink-0 border-t border-hair bg-app px-4 py-4 sm:px-8" data-testid="composer">
            <div className="mx-auto max-w-[820px]">
                <div
                    className="relative rounded-card border border-hair bg-panel shadow-card focus-within:border-accent"
                    {...draft.dropzoneProps}
                >
                    <AttachmentDropOverlay draft={draft} />
                    <AttachmentTextarea
                        ref={editorRef}
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
                        {composer.buttonLabel && (
                            <Button
                                variant="primary"
                                className="h-7 shrink-0"
                                onClick={submit}
                                pending={form.processing}
                                pendingLabel={uploading ? 'Uploading…' : undefined}
                                disabled={disabled || form.data.message.trim() === ''}
                                data-testid="composer-submit"
                            >
                                {composer.buttonLabel} <span className="ml-1.5 hidden text-[10px] opacity-70 pointer-fine:inline">⌘↵</span>
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
