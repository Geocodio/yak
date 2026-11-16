import { router } from '@inertiajs/react';
import { Badge, Button, ConfirmDialog, Toggle } from '@geocodio/console-ui';
import { Bell, Monitor, Smartphone, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Card } from '@/components/accounts/AccountsSection';
import { destroy, test, update } from '@/routes/settings/notifications/devices';
import type { PushDevice } from '@/types/notifications';

function describe(device: PushDevice): string {
    return device.label ?? device.userAgent ?? 'Unnamed device';
}

function lastUsed(device: PushDevice): string {
    if (device.lastUsedAt === null) {
        return 'No push sent yet';
    }

    const minutes = Math.round((Date.now() - new Date(device.lastUsedAt).getTime()) / 60_000);
    const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

    return `Last push ${minutes < 60 ? formatter.format(-Math.max(minutes, 0), 'minute') : minutes < 1440 ? formatter.format(-Math.round(minutes / 60), 'hour') : formatter.format(-Math.round(minutes / 1440), 'day')}`;
}

type TestState = 'sending' | 'sent' | 'failed';

export function DeviceList({ devices }: { devices: PushDevice[] }) {
    const [removing, setRemoving] = useState<PushDevice | null>(null);
    const [isBusy, setIsBusy] = useState(false);
    const [results, setResults] = useState<Record<number, TestState>>({});

    const sendTest = (device: PushDevice) => {
        setResults((current) => ({ ...current, [device.id]: 'sending' }));
        router.post(test.url(device.id), {}, {
            preserveScroll: true,
            onSuccess: () => setResults((current) => ({ ...current, [device.id]: 'sent' })),
            onError: () => setResults((current) => ({ ...current, [device.id]: 'failed' })),
        });
    };

    const confirmRemove = () => {
        if (removing === null) {
            return;
        }

        setIsBusy(true);
        router.delete(destroy.url(removing.id), { preserveScroll: true, onSuccess: () => setRemoving(null), onFinish: () => setIsBusy(false) });
    };

    return (
        <>
            <Card className="divide-y divide-hair" data-testid="push-device-list">
                {devices.map((device) => {
                    const result = results[device.id];
                    const note = device.lastError !== null ? 'Last push failed' : result === 'sending' ? 'Sending test...' : result === 'sent' ? 'Test sent' : result === 'failed' ? 'Test failed' : null;

                    return (
                        <div key={device.id} className="flex flex-wrap items-center gap-3 px-4 py-3" data-testid="push-device-row">
                            <span className="grid size-8 shrink-0 place-items-center rounded-control bg-panel-2 text-muted" aria-hidden="true">
                                {/iPhone|iPad|Android|Mobile/.test(device.userAgent ?? device.label ?? '') ? <Smartphone size={15} /> : <Monitor size={15} />}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2 text-[13px] font-medium">
                                    {describe(device)}
                                    {device.isCurrent && <Badge tone="accent">This device</Badge>}
                                    {/installed app/i.test(device.label ?? '') && <Badge>Installed app</Badge>}
                                </div>
                                <div className="text-[12px] text-muted">
                                    {lastUsed(device)}
                                    {note !== null && <span role="status" data-testid="push-device-note"> &middot; {note}</span>}
                                </div>
                            </div>
                            <Toggle checked={device.isEnabled} onCheckedChange={(value) => router.patch(update.url(device.id), { isEnabled: value }, { preserveScroll: true })} label={`Push to ${describe(device)}`} />
                            <Button variant="tertiary" icon={<Bell size={13} />} onClick={() => sendTest(device)} disabled={result === 'sending'} data-testid="push-device-test"><span className="max-sm:sr-only">Send a test</span></Button>
                            <Button variant="tertiary" icon={<Trash2 size={13} />} aria-label={`Remove ${describe(device)}`} onClick={() => setRemoving(device)} data-testid="push-device-remove"><span className="max-sm:sr-only">Remove</span></Button>
                        </div>
                    );
                })}
            </Card>

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Remove ${removing === null ? '' : describe(removing)}?`}
                body="Yak stops pushing to this device. You can turn notifications on again from it at any time."
                confirmLabel="Remove device"
                confirmTestId="push-device-remove-confirm"
                busy={isBusy}
                onConfirm={confirmRemove}
            />
        </>
    );
}
