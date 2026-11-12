import { Button, Dialog, Field, Select, TextInput, Textarea } from '@geocodio/console-ui';
import { useState } from 'react';
import { csrfToken } from '@/lib/csrfToken';
import { store } from '@/routes/threads/stack/children';

const KINDS = [
    { value: 'implement', label: 'Implement' },
    { value: 'research', label: 'Research' },
    { value: 'mockup', label: 'Mockup' },
    { value: 'general', label: 'General' },
];

const MODELS = [
    { value: 'sonnet', label: 'Sonnet' },
    { value: 'opus', label: 'Opus' },
    { value: 'haiku', label: 'Haiku' },
];

type AddChildDialogProps = { threadId: number; parentTitle: string; awake: number; limit: number; childCount: number; maxChildren: number; onClose: () => void; onAdded: () => void };

export function AddChildDialog({ threadId, parentTitle, awake, limit, childCount, maxChildren, onClose, onAdded }: AddChildDialogProps) {
    const [name, setName] = useState('');
    const [brief, setBrief] = useState('');
    const [kind, setKind] = useState<string | null>('implement');
    const [model, setModel] = useState<string | null>('sonnet');
    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const submit = () => {
        setIsSubmitting(true);
        setError(null);
        fetch(store.url(threadId), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ name, brief, kind, model }),
        })
            .then(async (response) => {
                if (!response.ok) {
                    const body = (await response.json().catch(() => ({}))) as { message?: string };
                    throw new Error(body.message ?? 'The sub-thread could not be added.');
                }

                onAdded();
                onClose();
            })
            .catch((failure: unknown) => {
                setError(failure instanceof Error ? failure.message : 'The sub-thread could not be added.');
                setIsSubmitting(false);
            });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !isSubmitting && onClose()}
            title="Add a sub-thread"
            description={`${parentTitle} hands it a brief and a copy of its sandbox as it is now.`}
            data-testid="stack-add-dialog"
            footer={
                <div className="flex items-center justify-end gap-2">
                    <Button variant="tertiary" disabled={isSubmitting} onClick={onClose}>Cancel</Button>
                    <Button variant="primary" disabled={name.trim() === '' || brief.trim() === ''} pending={isSubmitting} onClick={submit} data-testid="stack-add-submit">Start sub-thread</Button>
                </div>
            }
        >
            <div className="flex flex-col gap-3">
                <Field label="Name">
                    <TextInput value={name} onChange={(event) => setName(event.target.value)} maxLength={60} placeholder="Reverb events" data-testid="stack-add-name" />
                </Field>
                <Field label="Brief" description="What it should do, what to leave alone, and what to hand back.">
                    <Textarea value={brief} onChange={(event) => setBrief(event.target.value)} rows={5} placeholder="Broadcast thread state over private Reverb channels. Do not change the runner." data-testid="stack-add-brief" />
                </Field>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Kind">
                        <Select options={KINDS} value={kind} onChange={setKind} />
                    </Field>
                    <Field label="Model">
                        <Select options={MODELS} value={model} onChange={setModel} />
                    </Field>
                </div>
                <p className="rounded-card bg-panel-2 px-3 py-2 text-[12.5px] text-muted" data-testid="stack-add-note">
                    Works on its own branch. It runs on the same Claude account as {parentTitle}. {awake} of {limit} sub-threads are awake{awake >= limit ? ', so it waits for a slot' : ''}. {childCount} of {maxChildren} used.
                </p>
                {error && <p className="text-[12.5px] text-fail" data-testid="stack-add-error">{error}</p>}
            </div>
        </Dialog>
    );
}
