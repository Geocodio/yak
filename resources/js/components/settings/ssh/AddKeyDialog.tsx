import { Button, Dialog, Field, Textarea } from '@geocodio/console-ui';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { store } from '@/routes/settings/ssh-keys';

export function AddKeyDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm({ name: '', public_key: '' });
    const hasKeyError = form.errors.public_key !== undefined || form.errors.name !== undefined;

    useEffect(() => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = () => {
        const comment = form.data.public_key.trim().split(/\s+/)[2];
        form.transform((data) => ({ ...data, name: comment ?? 'SSH key' }));
        form.post(store.url(), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

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
                <Field label="Public key" error={hasKeyError ? 'Yak could not read this as a public key. It should be one line that starts with ssh-ed25519 or ssh-rsa, like the contents of id_ed25519.pub.' : undefined}>
                    <Textarea autoFocus rows={3} className="font-mono" placeholder="ssh-ed25519 AAAA..." value={form.data.public_key} onChange={(e) => form.setData('public_key', e.target.value)} data-testid="ssh-key-public" />
                </Field>
            </div>
        </Dialog>
    );
}
