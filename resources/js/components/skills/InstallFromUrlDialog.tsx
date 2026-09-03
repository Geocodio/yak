import { Button, Dialog, Field, TextInput } from '@geocodio/console-ui';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { install } from '@/routes/skills';

export function InstallFromUrlDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm({ url: '' });

    useEffect(() => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = () => {
        form.post(install.url(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Install from URL or path"
            description="Accepts a git URL (e.g. https://github.com/acme/plugin.git) or an absolute path on the Yak server."
            footer={
                <>
                    <Button variant="tertiary" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" pending={form.processing} pendingLabel="Installing…" onClick={submit} data-testid="install-from-url-submit">
                        Install
                    </Button>
                </>
            }
        >
            <Field label="URL or path" error={form.errors.url}>
                <TextInput
                    autoFocus
                    placeholder="https://github.com/owner/plugin.git"
                    value={form.data.url}
                    onChange={(e) => form.setData('url', e.target.value)}
                    data-testid="install-from-url-input"
                />
            </Field>
        </Dialog>
    );
}
