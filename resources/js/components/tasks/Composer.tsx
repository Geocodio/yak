import { useForm } from '@inertiajs/react';
import { Button, Textarea } from '@geocodio/console-ui';
import { useEffect, type KeyboardEvent } from 'react';
import { store as storeMessage } from '@/routes/tasks/messages';
import type { ComposerData } from '@/types/tasks';

export function Composer({ taskId, composer, fillValue }: { taskId: number; composer: ComposerData; fillValue: string | null }) {
    const form = useForm({ message: '' });
    const disabled = composer.state === 'disabled_failed' || composer.state === 'disabled_closed';

    useEffect(() => {
        if (fillValue !== null) {
            form.setData('message', fillValue);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fillValue]);

    const submit = () => {
        if (disabled || form.data.message.trim() === '') {
            return;
        }
        form.post(storeMessage.url(taskId), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            event.preventDefault();
            submit();
        }
    };

    return (
        <div className="shrink-0 border-t border-hair bg-app px-8 py-4">
            <div className="mx-auto max-w-[820px]">
                <div className="rounded-card border border-hair bg-panel shadow-card focus-within:border-accent">
                    <Textarea
                        rows={2}
                        placeholder={composer.placeholder}
                        value={form.data.message}
                        onChange={(event) => form.setData('message', event.target.value)}
                        onKeyDown={onKeyDown}
                        disabled={disabled}
                        className="w-full resize-none border-0 bg-transparent shadow-none focus:ring-0"
                        data-testid="composer-input"
                    />
                    <div className="flex items-center justify-between border-t border-hair px-3 py-2">
                        <span className="text-[11px] text-faint">{composer.note}</span>
                        {composer.buttonLabel && (
                            <Button
                                variant="primary"
                                className="h-7"
                                onClick={submit}
                                pending={form.processing}
                                disabled={disabled || form.data.message.trim() === ''}
                                data-testid="composer-submit"
                            >
                                {composer.buttonLabel} <span className="ml-1.5 text-[10px] opacity-70">⌘↵</span>
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
