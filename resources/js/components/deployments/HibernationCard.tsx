import { Badge, Button, Field, TextInput, Toggle } from '@geocodio/console-ui';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import hibernation from '@/routes/deployments/hibernation';
import type { HibernationData } from '@/types/deployments';

export function HibernationCard({ deploymentId, hibernation: data }: { deploymentId: number; hibernation: HibernationData }) {
    const [timeout, setTimeout_] = useState(data.timeout);
    const form = useForm({ timeout: data.timeout });

    const toggle = (checked: boolean) => {
        router.patch(
            hibernation.update.url(deploymentId),
            { long_lived: checked },
            { preserveScroll: true },
        );
    };

    const saveTimeout = () => {
        form.transform(() => ({ long_lived: true, timeout }));
        form.patch(hibernation.update.url(deploymentId), { preserveScroll: true });
    };

    return (
        <section className="rounded-card border border-hair bg-panel shadow-card">
            <div className="flex items-center justify-between border-b border-hair px-4 py-2.5">
                <h2 className="text-[12px] font-semibold uppercase tracking-wide text-faint">Hibernation</h2>
                <Badge tone={data.longLived ? 'info' : 'neutral'}>{data.longLived ? 'Long-lived' : 'Standard'}</Badge>
            </div>
            <div className="p-4">
                <p className="text-[12px] text-muted">
                    {data.longLived ? `Hibernates after ${data.timeout} of inactivity. Exempt from the 30-day cleanup.` : `Hibernates after ${data.timeout} of inactivity.`}
                </p>
                <div className="mt-3 flex items-center justify-between">
                    <span className="text-[12.5px]">Keep this branch long-lived</span>
                    <Toggle checked={data.longLived} onCheckedChange={toggle} label="Keep this branch long-lived" />
                </div>
                {data.autoLongLived && data.longLived && (
                    <p className="mt-2 text-[11.5px] text-faint">Auto-enabled because this is a release branch.</p>
                )}
                {data.longLived && (
                    <div className="mt-3 flex items-end gap-2">
                        <Field label="Hibernation timeout" description="e.g. 3d, 12h, 2w" className="flex-1" error={form.errors.timeout}>
                            <TextInput value={timeout} onChange={(e) => setTimeout_(e.target.value)} className="font-mono" />
                        </Field>
                        <Button className="mb-[22px]" pending={form.processing} onClick={saveTimeout}>
                            Save
                        </Button>
                    </div>
                )}
            </div>
        </section>
    );
}
