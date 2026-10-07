import { useForm } from '@inertiajs/react';
import { BrandMarkIcon, Button, Dialog, Kbd, cn } from '@geocodio/console-ui';
import { ChevronDown, Search, Wrench } from 'lucide-react';
import { useEffect, useRef, useState, type ChangeEvent, type KeyboardEvent } from 'react';
import { RepoPicker } from '@/components/tasks/RepoPicker';
import { YAK_MARK } from '@/lib/brand';
import { store } from '@/routes/tasks';

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
    const form = useForm({ repo: '', mode: 'fix' as TaskMode, description: '' });
    const [pickerOpen, setPickerOpen] = useState(false);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
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
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
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

    const onDescriptionInput = (event: ChangeEvent<HTMLTextAreaElement>) => {
        form.setData('description', event.target.value);
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
            <div className="flex items-start gap-3 px-[18px] pb-2 pt-4">
                <BrandMarkIcon mark={YAK_MARK} size={26} className="mt-[3px] shrink-0" />
                <textarea
                    ref={textareaRef}
                    rows={4}
                    placeholder="What should Yak do? Type @ to pick a repo…"
                    value={form.data.description}
                    onChange={onDescriptionInput}
                    className="min-h-24 w-full resize-none bg-transparent text-[16px] leading-[1.55] text-body outline-none placeholder:text-faint"
                    data-testid="new-task-description"
                />
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
        </Dialog>
    );
}
