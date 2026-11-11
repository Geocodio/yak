import { Button, Dialog, Field, Select, TextInput, Textarea } from '@geocodio/console-ui';
import { useState } from 'react';
import { csrfToken } from '@/lib/csrfToken';
import { store } from '@/routes/threads/stack/children';

const KINDS = [
    { value: 'general', label: 'General' },
    { value: 'research', label: 'Research' },
    { value: 'implement', label: 'Implement' },
    { value: 'mockup', label: 'Mockup' },
];

const MODELS = [
    { value: 'sonnet', label: 'Sonnet' },
    { value: 'opus', label: 'Opus' },
    { value: 'haiku', label: 'Haiku' },
];

export function AddChildDialog({ threadId, onClose, onAdded }: { threadId: number; onClose: () => void; onAdded: () => void }) {
    const [name, setName] = useState('');
    const [brief, setBrief] = useState('');
    const [kind, setKind] = useState<string | null>('general');
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
            title="Add sub-thread"
            description="It starts with the brief you write here and reports back to this thread."
            data-testid="stack-add-dialog"
            footer={
                <div className="flex items-center justify-end gap-2">
                    <Button variant="tertiary" disabled={isSubmitting} onClick={onClose}>Cancel</Button>
                    <Button variant="primary" disabled={name.trim() === '' || brief.trim() === ''} pending={isSubmitting} onClick={submit} data-testid="stack-add-submit">Add sub-thread</Button>
                </div>
            }
        >
            <div className="flex flex-col gap-3">
                <Field label="Name">
                    <TextInput value={name} onChange={(event) => setName(event.target.value)} maxLength={60} data-testid="stack-add-name" />
                </Field>
                <Field label="Brief">
                    <Textarea value={brief} onChange={(event) => setBrief(event.target.value)} rows={5} data-testid="stack-add-brief" />
                </Field>
                <Field label="Kind">
                    <Select options={KINDS} value={kind} onChange={setKind} />
                </Field>
                <Field label="Model">
                    <Select options={MODELS} value={model} onChange={setModel} />
                </Field>
                {error && <p className="text-[12.5px] text-fail" data-testid="stack-add-error">{error}</p>}
            </div>
        </Dialog>
    );
}
