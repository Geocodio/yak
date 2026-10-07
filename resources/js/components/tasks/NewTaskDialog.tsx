import { useForm } from '@inertiajs/react';
import { BrandMarkIcon, Button, Dialog, Kbd, cn } from '@geocodio/console-ui';
import { ChevronDown, Search, Wrench } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import { AttachButton, AttachmentDropOverlay, DraftAttachments } from '@/components/attachments/Attachments';
import { AttachmentTextarea, type AttachmentTextareaHandle } from '@/components/attachments/AttachmentTextarea';
import { useAttachmentDraft } from '@/components/attachments/useAttachmentDraft';
import { MediaLightbox } from '@/components/tasks/MediaLightbox';
import { RepoPicker } from '@/components/tasks/RepoPicker';
import { YAK_MARK } from '@/lib/brand';
import { store } from '@/routes/tasks';
import type { MediaItem } from '@/types/tasks';

type TaskMode = 'fix' | 'research';

const MODES: { mode: TaskMode; label: string; icon: typeof Wrench; hint: string }[] = [
    { mode: 'fix', label: 'Fix', icon: Wrench, hint: 'Yak makes the change and opens a PR for review.' },
    { mode: 'research', label: 'Research', icon: Search, hint: 'Yak investigates and writes a report. No PR.' },
];

function initials(slug: string): string {
    return slug
        .split(/[-/]/)
        .map((part) => part[0] ?? '')
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

/**
 * The type-first composer for starting a task from the dashboard. The
 * description is the whole surface; the repository and mode live in one bar
 * underneath it. Typing `@` in the description opens the repository picker.
 * Files pasted, dropped or picked attach with an `[Image #1]` token in the
 * description, as in the task composer.
 */
export function NewTaskDialog({
    open,
    onOpenChange,
    repoOptions,
    defaultRepo,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    repoOptions: string[];
    defaultRepo: string | null;
}) {
    const form = useForm<{ repo: string; mode: TaskMode; description: string; attachments: File[]; attachment_refs: string[] }>({
        repo: '',
        mode: 'fix',
        description: '',
        attachments: [],
        attachment_refs: [],
    });
    const [pickerOpen, setPickerOpen] = useState(false);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const editorRef = useRef<AttachmentTextareaHandle>(null);
    const draft = useAttachmentDraft({ enabled: open, onAdd: (added) => editorRef.current?.insertTokens(added) });
    const [lightboxMedia, setLightboxMedia] = useState<MediaItem[] | null>(null);
    const [lightboxIndex, setLightboxIndex] = useState(0);
    const selectedMode = MODES.find((entry) => entry.mode === form.data.mode) ?? MODES[0];
    const canSubmit = form.data.repo !== '' && form.data.description.trim().length >= 3;

    useEffect(() => {
        if (open) {
            setPickerOpen(false);
        }
    }, [open]);

    const submit = () => {
        if (!canSubmit || form.processing) {
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

    const onPopupKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            event.preventDefault();
            submit();
        }
    };

    const onDescriptionInput = (event: FormEvent<HTMLTextAreaElement>) => {
        const typed = (event.nativeEvent as InputEvent).data;
        if (typed === '@' && form.data.repo === '') {
            setPickerOpen(true);
        }
    };

    const onRepoPicked = (repo: string | null) => {
        const description = form.data.description;
        form.setData({
            ...form.data,
            repo: repo ?? '',
            description: repo !== null && description.endsWith('@') ? description.slice(0, -1) : description,
        });
        if (repo !== null) {
            setPickerOpen(false);
            textareaRef.current?.focus();
        }
    };

    const errors = form.errors as Record<string, string | undefined>;
    const error = form.errors.repo ?? form.errors.description ?? form.errors.mode;

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="New task"
            hideTitle
            width="w-[min(640px,100vw)]"
            className="overflow-visible rounded-xl p-0"
            onPopupKeyDown={onPopupKeyDown}
            initialFocus={textareaRef}
            data-testid="new-task-dialog"
        >
            <div className="relative" {...draft.dropzoneProps}>
                <AttachmentDropOverlay draft={draft} />
                <div className="flex items-start gap-3 px-[18px] pb-2 pt-4">
                    <BrandMarkIcon mark={YAK_MARK} size={26} className="mt-[3px] shrink-0" />
                    <AttachmentTextarea
                        ref={editorRef}
                        inputRef={textareaRef}
                        draft={draft}
                        rows={4}
                        placeholder="What should Yak do? Type @ to pick a repo, or paste and drop screenshots and files…"
                        value={form.data.description}
                        onValueChange={(value) => form.setData('description', value)}
                        onInput={onDescriptionInput}
                        onPaste={draft.onPaste}
                        wrapperClassName="min-w-0 flex-1"
                        className="min-h-24 w-full resize-none rounded-none border-0 bg-transparent px-0 py-0 text-[16px] leading-[1.55] shadow-none outline-none hover:border-0 focus:shadow-none"
                        data-testid="new-task-description"
                    />
                </div>
                <div className="pl-[44px] pr-[6px]">
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
            </div>

            <div className="relative">
                <div className="flex flex-wrap items-center gap-2 border-t border-hair py-2.5 pl-[18px] pr-3">
                    <button
                        type="button"
                        onClick={() => setPickerOpen(true)}
                        className={cn(
                            'inline-flex h-7 items-center gap-1.5 rounded-control border bg-panel pl-2 pr-2.5 text-[12.5px] font-medium text-body hover:bg-panel-2',
                            form.data.repo === '' ? 'border-dashed border-hair-strong text-muted' : 'border-hair-strong',
                            form.errors.repo && 'border-fail',
                        )}
                        data-testid="new-task-repo"
                    >
                        <span
                            className={cn(
                                'grid size-4 place-items-center rounded-[4px] font-mono text-[9px] font-semibold',
                                form.data.repo === '' ? 'bg-panel-2 text-faint' : 'bg-brand text-accent-ink',
                            )}
                        >
                            {form.data.repo === '' ? '@' : initials(form.data.repo)}
                        </span>
                        {form.data.repo === '' ? 'Pick a repo' : form.data.repo}
                        <ChevronDown size={12} className="text-faint" aria-hidden="true" />
                    </button>

                    <div className="inline-flex h-7 gap-0.5 rounded-control bg-panel-2 p-0.5" role="group" aria-label="Mode">
                        {MODES.map(({ mode, label, icon: Icon, hint }) => (
                            <button
                                key={mode}
                                type="button"
                                aria-pressed={form.data.mode === mode}
                                title={hint}
                                onClick={() => form.setData('mode', mode)}
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-[5px] px-2.5 text-[12.5px] font-medium transition-colors',
                                    form.data.mode === mode ? 'bg-accent-soft text-body' : 'text-muted hover:text-body',
                                )}
                                data-testid={`mode-${mode}`}
                            >
                                <Icon size={13} className={form.data.mode === mode ? (mode === 'fix' ? 'text-ok' : 'text-info') : undefined} aria-hidden="true" />
                                {label}
                            </button>
                        ))}
                    </div>

                    <AttachButton draft={draft} disabled={form.processing} />

                    <div className="ml-auto flex items-center gap-2.5">
                        <Kbd keys={['⌘', '↵']} className="hidden pointer-fine:inline-flex" />
                        <Button
                            variant="primary"
                            className="h-7"
                            pending={form.processing}
                            disabled={!canSubmit}
                            onClick={submit}
                            data-testid="new-task-submit"
                        >
                            Start {selectedMode.label.toLowerCase()}
                        </Button>
                    </div>
                </div>

                {pickerOpen && (
                    <RepoPicker
                        options={repoOptions}
                        defaultOption={defaultRepo}
                        value={form.data.repo || null}
                        onChange={onRepoPicked}
                        onClose={() => setPickerOpen(false)}
                        autoFocus
                        className="absolute left-[18px] top-full z-20 -mt-0.5 w-[min(300px,calc(100%-36px))]"
                    />
                )}
            </div>

            {error && (
                <p className="px-[18px] pb-3 text-[12px] text-fail" data-testid="new-task-error">
                    {error}
                </p>
            )}

            <MediaLightbox
                media={lightboxMedia}
                index={lightboxIndex}
                onOpenChange={(isOpen) => !isOpen && setLightboxMedia(null)}
                onIndexChange={setLightboxIndex}
            />
        </Dialog>
    );
}
