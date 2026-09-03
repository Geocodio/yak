import { Button, Field, TextInput, Textarea } from '@geocodio/console-ui';
import { useForm } from '@inertiajs/react';
import repos from '@/routes/repos';
import type { ManifestData } from '@/types/repositories';

export function ManifestCard({ repoSlug, manifest }: { repoSlug: string; manifest: ManifestData }) {
    const form = useForm<ManifestData>({
        port: manifest.port,
        healthProbePath: manifest.healthProbePath,
        coldStart: manifest.coldStart,
        checkoutRefresh: manifest.checkoutRefresh,
        wakeTimeoutSeconds: manifest.wakeTimeoutSeconds,
    });

    const submit = () => {
        form.transform((data) => ({
            port: data.port,
            health_probe_path: data.healthProbePath,
            cold_start: data.coldStart,
            checkout_refresh: data.checkoutRefresh,
            wake_timeout_seconds: data.wakeTimeoutSeconds,
        }));
        form.put(repos.manifest.update.url(repoSlug), { preserveScroll: true });
    };

    return (
        <section className="rounded-card border border-hair bg-panel shadow-card">
            <div className="flex items-center justify-between border-b border-hair px-4 py-2.5">
                <h2 className="text-[12px] font-semibold uppercase tracking-wide text-faint">Preview manifest</h2>
            </div>
            <div className="flex flex-col gap-3 p-4">
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Port" error={form.errors.port}>
                        <TextInput type="number" value={form.data.port} onChange={(e) => form.setData('port', Number(e.target.value))} />
                    </Field>
                    <Field label="Health probe path" error={form.errors.healthProbePath}>
                        <TextInput value={form.data.healthProbePath} onChange={(e) => form.setData('healthProbePath', e.target.value)} className="font-mono" />
                    </Field>
                </div>
                <Field label="Cold start command">
                    <Textarea rows={2} className="font-mono text-[11.5px]" value={form.data.coldStart} onChange={(e) => form.setData('coldStart', e.target.value)} />
                </Field>
                <Field label="Checkout refresh command">
                    <Textarea
                        rows={2}
                        className="font-mono text-[11.5px]"
                        value={form.data.checkoutRefresh}
                        onChange={(e) => form.setData('checkoutRefresh', e.target.value)}
                    />
                </Field>
                <div className="flex items-end gap-2">
                    <Field label="Wake timeout (seconds)" className="flex-1">
                        <TextInput type="number" value={form.data.wakeTimeoutSeconds} onChange={(e) => form.setData('wakeTimeoutSeconds', Number(e.target.value))} />
                    </Field>
                    <Button variant="primary" pending={form.processing} onClick={submit}>
                        Save
                    </Button>
                </div>
            </div>
        </section>
    );
}
