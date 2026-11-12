import { useForm } from '@inertiajs/react';
import { Button, Dialog, Field, TextInput } from '@geocodio/console-ui';
import { update } from '@/routes/settings/claude-accounts';
import type { ClaudeAccount, ModelFamily } from '@/types/claudeAccounts';
import { ModelPicker } from './ModelPicker';
import { AppearanceFields } from './AppearanceFields';

/** Name, default model and appearance of an existing account. The secret has its own dialog. */
export function EditAccountDialog({ account, models, onClose }: { account: ClaudeAccount; models: ModelFamily[]; onClose: () => void }) {
    const form = useForm({ name: account.name, default_model: account.defaultModel, icon: account.icon, color: account.color, max_concurrent_turns: account.maxConcurrentTurns });

    const save = () => form.patch(update.url(account.id), { preserveScroll: true, onSuccess: onClose });

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Edit ${account.name}`}
            description="The name and appearance only matter to you and the people who can use this account. The secret is not touched."
            width="w-[min(540px,100vw)]"
            footer={
                <div className="flex items-center justify-end gap-2">
                    <Button variant="tertiary" onClick={onClose}>Cancel</Button>
                    <Button variant="primary" disabled={form.data.name.trim() === '' || !(form.data.max_concurrent_turns >= 1)} pending={form.processing} onClick={save} data-testid="account-edit-save">Save</Button>
                </div>
            }
        >
            <div className="flex flex-col gap-5">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Name" error={form.errors.name}><TextInput value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                    <Field label="Default model" description="Used when a task does not pick one."><ModelPicker models={models} value={form.data.default_model} onChange={(value) => form.setData('default_model', value)} testId="account-default-model" /></Field>
                </div>
                <Field label="Concurrent turns" description="How many turns may run on this account at once." error={form.errors.max_concurrent_turns}>
                    <TextInput type="number" min={1} max={64} data-testid="account-max-concurrent-turns" value={String(form.data.max_concurrent_turns)} onChange={(event) => form.setData('max_concurrent_turns', Number(event.target.value))} />
                </Field>
                <AppearanceFields value={{ icon: form.data.icon, color: form.data.color }} onChange={(next) => form.setData((data) => ({ ...data, ...next }))} />
            </div>
        </Dialog>
    );
}
