import { router } from '@inertiajs/react';
import { Button, Checkbox, Dialog } from '@geocodio/console-ui';
import { AlertTriangle } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { csrfToken } from '@/lib/csrfToken';
import { show, update } from '@/routes/threads/share';
import type { ThreadShareMode, ThreadShareState } from '@/types/thread-share';
import type { ThreadData } from '@/types/threads';

const MODES: { value: ThreadShareMode; label: string; description: string }[] = [
    { value: 'only_me', label: 'Only me', description: 'Nobody else can see this thread, its changes or its details.' },
    { value: 'people', label: 'People I pick', description: 'They can read the whole conversation, the changes and the details.' },
    { value: 'everyone', label: 'Everyone on the team', description: 'Anyone with a Yak account on this team can read it.' },
];

export function ShareThreadDialog({ thread, onClose }: { thread: ThreadData; onClose: () => void }) {
    const [state, setState] = useState<ThreadShareState | null>(null);
    const [hasLoadFailed, setHasLoadFailed] = useState(false);
    const [mode, setMode] = useState<ThreadShareMode>('only_me');
    const [userIds, setUserIds] = useState<number[]>([]);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [hasSaveFailed, setHasSaveFailed] = useState(false);

    const load = useCallback(() => {
        setHasLoadFailed(false);
        fetch(show.url(thread.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? (response.json() as Promise<ThreadShareState>) : Promise.reject(new Error(String(response.status)))))
            .then((loaded) => { setState(loaded); setMode(loaded.mode); setUserIds(loaded.userIds); })
            .catch(() => setHasLoadFailed(true));
    }, [thread.id]);

    useEffect(load, [load]);

    const save = () => {
        if (isSubmitting || state === null) {
            return;
        }

        setIsSubmitting(true);
        setHasSaveFailed(false);
        fetch(update.url(thread.id), {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ mode, user_ids: mode === 'people' ? userIds : [] }),
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                router.reload({ only: ['thread', 'publishWarning'], onFinish: onClose });
            })
            .catch(() => { setHasSaveFailed(true); setIsSubmitting(false); });
    };

    const toggle = (id: number, checked: boolean) => setUserIds((ids) => (checked ? [...ids, id] : ids.filter((other) => other !== id)));
    const hasSecrets = state !== null && state.secretFiles.length > 0;
    const canSubmit = state !== null && !isSubmitting && (mode !== 'people' || userIds.length > 0);

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !isSubmitting && onClose()}
            title="Share this thread"
            description={thread.visibility === 'team' ? 'A thread started on Yak is visible to the team. Choose Only me to make it private.' : undefined}
            width="w-[min(540px,100vw)]"
            data-testid="share-dialog"
            footer={
                <div className="flex items-center justify-between gap-2">
                    <a href="https://yak.build/docs/sharing" target="_blank" rel="noreferrer" className="text-[12.5px] text-accent-text hover:underline">How sharing works</a>
                    <div className="flex items-center gap-2">
                        <Button variant="tertiary" disabled={isSubmitting} onClick={onClose}>Cancel</Button>
                        <Button variant="primary" disabled={!canSubmit} pending={isSubmitting} onClick={save} data-testid="share-submit">Share</Button>
                    </div>
                </div>
            }
        >
            <div className="flex max-h-[60vh] flex-col gap-4 overflow-auto">
                {hasLoadFailed && (
                    <div role="alert" data-testid="share-load-error" className="flex items-center justify-between gap-3 rounded-control border border-hair bg-panel-2 px-3 py-2 text-[12.5px]">
                        <span>Could not load the sharing options. Nothing has changed.</span>
                        <Button variant="secondary" onClick={load}>Try again</Button>
                    </div>
                )}

                {state !== null && (
                    <>
                        <div role="radiogroup" aria-label="Who can read this thread" className="flex flex-col gap-2">
                            {MODES.map((option) => (
                                <label key={option.value} className="flex cursor-pointer items-start gap-2.5 rounded-control border border-hair px-3 py-2">
                                    <input
                                        type="radio"
                                        name="share-mode"
                                        className="mt-1 accent-accent"
                                        checked={mode === option.value}
                                        disabled={isSubmitting}
                                        onChange={() => setMode(option.value)}
                                        data-testid={`share-mode-${option.value}`}
                                    />
                                    <span>
                                        <span className="block text-[13.5px] font-medium">{option.label}</span>
                                        <span className="block text-[12.5px] text-muted">{option.description}</span>
                                    </span>
                                </label>
                            ))}
                        </div>

                        {mode === 'people' && (
                            <div className="flex flex-col gap-1.5" data-testid="share-people">
                                {state.people.length === 0 && <p className="text-[12.5px] text-muted">Nobody else has a Yak account on this team yet.</p>}
                                {state.people.map((person) => (
                                    <Checkbox
                                        key={person.id}
                                        label={person.name}
                                        checked={userIds.includes(person.id)}
                                        disabled={isSubmitting}
                                        onCheckedChange={(checked) => toggle(person.id, checked)}
                                        data-testid={`share-person-${person.id}`}
                                    />
                                ))}
                            </div>
                        )}

                        {hasSecrets && (
                            <div data-testid="share-secret-warning" className="flex flex-col gap-2 rounded-control border border-hair bg-panel-2 p-3 text-[12.5px]">
                                <p className="flex items-start gap-1.5"><AlertTriangle size={14} className="mt-0.5 shrink-0 text-warn" aria-hidden="true" />
                                    <span>Claude opened {state.secretFiles.length} {state.secretFiles.length === 1 ? 'file' : 'files'} with names that look like secrets. Yak does not remove secrets from transcripts. Everyone you share with can read what Claude saw. You can stop sharing later, but people who already read it keep what they saw.</span>
                                </p>
                                <ul className="font-mono text-[12px] text-muted">{state.secretFiles.map((file) => <li key={file} className="break-all">{file}</li>)}</ul>
                                {state.memoryFolder !== null && (
                                    <div>
                                        <p>Memory folder, readable once shared: <code className="font-mono text-[12px]">{state.memoryFolder}</code></p>
                                        <ul className="font-mono text-[12px] text-muted">{state.memoryFiles.map((file) => <li key={file} className="break-all">{file}</li>)}</ul>
                                    </div>
                                )}
                            </div>
                        )}
                    </>
                )}

                {hasSaveFailed && (
                    <p role="alert" data-testid="share-error" className="rounded-control border border-hair bg-panel-2 px-3 py-2 text-[12.5px]">
                        <strong className="font-medium">Could not save.</strong> Yak could not reach its server, so nothing changed. This thread is still {thread.visibility === 'private' ? 'private' : 'visible to the team'}. Try again in a moment.
                    </p>
                )}
            </div>
        </Dialog>
    );
}
