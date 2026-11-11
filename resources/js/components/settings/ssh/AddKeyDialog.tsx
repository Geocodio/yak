import { Button, Dialog, Field, Textarea, TextInput } from '@geocodio/console-ui';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { store } from '@/routes/settings/ssh-keys';

export function AddKeyDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm({ name: '', public_key: '' });

    useEffect(() => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = () => form.post(store.url(), { preserveScroll: true, onSuccess: () => onOpenChange(false) });

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Add an SSH key"
            footer={
                <>
                    <Button variant="tertiary" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" pending={form.processing} pendingLabel="Adding…" onClick={submit} data-testid="ssh-add-submit">
                        Add key
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-4">
                <Field label="Name" error={form.errors.name}>
                    <TextInput autoFocus placeholder="Work computer" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} data-testid="ssh-key-name" />
                </Field>
                <Field label="Public key" error={form.errors.public_key}>
                    <Textarea rows={3} className="font-mono" placeholder="ssh-ed25519 AAAA..." value={form.data.public_key} onChange={(e) => form.setData('public_key', e.target.value)} data-testid="ssh-key-public" />
                </Field>
            </div>
        </Dialog>
    );
}
