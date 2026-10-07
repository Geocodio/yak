import { useForm } from '@inertiajs/react';
import { Button, Field, Select, Sheet, cn } from '@geocodio/console-ui';
import { useRef, useState, type KeyboardEvent } from 'react';
import { AttachButton, AttachmentDropOverlay, DraftAttachments } from '@/components/attachments/Attachments';
import { AttachmentTextarea, type AttachmentTextareaHandle } from '@/components/attachments/AttachmentTextarea';
import { useAttachmentDraft } from '@/components/attachments/useAttachmentDraft';
import { MediaLightbox } from '@/components/tasks/MediaLightbox';
import { store } from '@/routes/tasks';
import type { MediaItem } from '@/types/tasks';

type TaskMode = 'fix' | 'research';

export function NewTaskSheet({
    open,
    onOpenChange,
    repoOptions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    repoOptions: string[];
}) {
    const form = useForm<{ repo: string; mode: TaskMode; description: string; attachments: File[]; attachment_refs: string[] }>({
        repo: '',
        mode: 'fix',
        description: '',
        attachments: [],
        attachment_refs: [],
    });
    const editorRef = useRef<AttachmentTextareaHandle>(null);
    const draft = useAttachmentDraft({ enabled: open, onAdd: (added) => editorRef.current?.insertTokens(added) });
    const [lightboxMedia, setLightboxMedia] = useState<MediaItem[] | null>(null);
    const [lightboxIndex, setLightboxIndex] = useState(0);
    const errors = form.errors as Record<string, string | undefined>;

    const submit = () => {
        if (form.processing) {
            return;
        }
        form.transform((data) => ({ ...data, attachments: draft.files, attachment_refs: draft.refs }));
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                draft.clear();
                onOpenChange(false);
            },
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            event.preventDefault();
            submit();
        }
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange} title="New task"
            width="w-[min(440px,100vw)]"
            className="pt-[max(1rem,env(safe-area-inset-top))] pb-[max(1rem,env(safe-area-inset-bottom))]"
        >
            <div className="flex flex-col gap-4 pt-2" data-testid="new-task-sheet" onKeyDown={onKeyDown}>
                <Field label="Repository" error={form.errors.repo}>
                    <Select
                        options={repoOptions.map((slug) => ({ value: slug, label: slug }))}
                        value={form.data.repo || null}
                        onChange={(value) => form.setData('repo', value ?? '')}
                        placeholder="Choose a repository…"
                    />
                </Field>
                <div>
                    <div className="mb-1.5 text-[12px] font-medium">Mode</div>
                    <div className="grid grid-cols-2 gap-2">
                        {(['fix', 'research'] as const).map((mode) => (
                            <button
                                key={mode}
                                type="button"
                                data-testid={`mode-${mode}`}
                                onClick={() => form.setData('mode', mode)}
                                className={cn(
                                    'min-w-0 rounded-card border border-hair bg-panel p-3 text-left hover:border-hair-strong',
                                    form.data.mode === mode && 'border-accent bg-accent-soft',
                                )}
                            >
                                <div className="text-[13px] font-medium capitalize">{mode}</div>
                                <div className="mt-0.5 text-[12px] text-muted">
                                    {mode === 'fix' ? 'Yak makes the change and opens a PR.' : 'Yak investigates and writes a report. No PR.'}
                                </div>
                            </button>
                        ))}
                    </div>
                    {form.errors.mode && <p className="mt-1 text-[12px] text-fail">{form.errors.mode}</p>}
                </div>
                <Field label="Description" error={form.errors.description}>
                    <div className="relative" {...draft.dropzoneProps}>
                        <AttachmentDropOverlay draft={draft} />
                        <AttachmentTextarea
                            ref={editorRef}
                            draft={draft}
                            rows={6}
                            placeholder="Describe what you'd like Yak to do… Paste or drop screenshots and files here."
                            value={form.data.description}
                            onValueChange={(value) => form.setData('description', value)}
                            onPaste={draft.onPaste}
                            wrapperClassName="rounded-control bg-panel"
                            className="w-full"
                            data-testid="new-task-description"
                        />
                    </div>
                </Field>
                <div className="-mt-2 -mx-3">
                    <DraftAttachments
                        draft={draft}
                        errors={errors}
                        progress={form.processing && draft.items.length > 0 ? (form.progress?.percentage ?? 0) : null}
                        onOpenMedia={(items, index) => {
                            setLightboxMedia(items);
                            setLightboxIndex(index);
                        }}
                    />
                </div>
                <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <AttachButton draft={draft} disabled={form.processing} />
                        <span className="hidden text-[11px] text-faint pointer-fine:inline">⌘↵ to submit</span>
                    </div>
                    <div className="ml-auto flex gap-2">
                        <Button onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button variant="primary" pending={form.processing} onClick={submit} data-testid="new-task-submit">
                            Start task
                        </Button>
                    </div>
                </div>
            </div>
            <MediaLightbox
                media={lightboxMedia}
                index={lightboxIndex}
                onOpenChange={(isOpen) => !isOpen && setLightboxMedia(null)}
                onIndexChange={setLightboxIndex}
            />
        </Sheet>
    );
}
