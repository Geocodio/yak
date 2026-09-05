import { useForm } from '@inertiajs/react';
import { Button, Field, Select, Sheet, Textarea, cn } from '@geocodio/console-ui';
import type { KeyboardEvent } from 'react';
import { store } from '@/routes/tasks';

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
    const form = useForm({ repo: '', mode: 'fix' as TaskMode, description: '' });

    const submit = () => {
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
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
        <Sheet open={open} onOpenChange={onOpenChange} title="New task" width="w-[min(440px,100vw)]">
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
                    <Textarea
                        rows={6}
                        placeholder="Describe what you'd like Yak to do…"
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        data-testid="new-task-description"
                    />
                </Field>
                <div className="flex items-center justify-between">
                    <span className="text-[11px] text-faint">⌘↵ to submit</span>
                    <div className="flex gap-2">
                        <Button onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button variant="primary" pending={form.processing} onClick={submit} data-testid="new-task-submit">
                            Start task
                        </Button>
                    </div>
                </div>
            </div>
        </Sheet>
    );
}
