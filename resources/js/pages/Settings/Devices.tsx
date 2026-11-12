import { Deferred, Head, Link, router } from '@inertiajs/react';
import { Badge, Button, ConfirmDialog, EmptyState, Skeleton, StatusPill } from '@geocodio/console-ui';
import { AlertTriangle, Check, Monitor, RefreshCw, Trash2 } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { Card } from '@/components/accounts/AccountsSection';
import { AddComputerCard } from '@/components/settings/devices/AddComputerCard';
import { systemLabel } from '@/components/settings/devices/systemLabel';
import { useDocsUrl } from '@/lib/docsUrl';
import { SettingsLayout } from '@/layouts/SettingsLayout';
import { destroy } from '@/routes/settings/devices';
import { show as showThread } from '@/routes/threads';
import type { Device } from '@/types/devices';
import type { PageProps } from '@/types/shared';

type Props = PageProps<{
    devices?: Device[];
}>;

const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

function relative(iso: string): string {
    const days = Math.round((new Date(iso).getTime() - Date.now()) / 86_400_000);
    const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

    if (Math.abs(days) >= 1) {
        return formatter.format(days, 'day');
    }

    const minutes = Math.round((new Date(iso).getTime() - Date.now()) / 60_000);

    return Math.abs(minutes) < 1 ? 'just now' : Math.abs(minutes) < 60 ? formatter.format(minutes, 'minute') : formatter.format(Math.round(minutes / 60), 'hour');
}

function shortDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric' });
}

function LoadingRows() {
    return (
        <Card className="divide-y divide-hair" aria-busy="true" aria-label="Loading computers" data-testid="devices-loading">
            {[0, 1].map((index) => (
                <div key={index} className="flex items-center gap-3 px-4 py-3.5">
                    <Skeleton className="size-8 rounded-control" />
                    <div className="flex flex-1 flex-col gap-2"><Skeleton className="h-3.5 w-40" /><Skeleton className="h-3 w-64 max-w-full" /></div>
                    <Skeleton className="h-8 w-20" />
                </div>
            ))}
        </Card>
    );
}

function EmptyDevices() {
    const guideUrl = useDocsUrl('teleport/');

    return (
        <div className="flex flex-col gap-4" data-testid="no-devices">
            <Card className="p-8">
                <EmptyState
                    icon={<Monitor size={18} />}
                    title="No computers paired"
                    body="Pair a computer to teleport threads between it and Yak. Until then threads you start on Yak stay on Yak."
                    action={<a href={guideUrl} target="_blank" rel="noreferrer" className={`rounded-[3px] text-[12.5px] text-accent-text hover:underline ${FOCUS}`}>How teleport works</a>}
                />
            </Card>
            <AddComputerCard heading="Pair your first computer" />
        </div>
    );
}

function DeviceRow({ device, onRevoke }: { device: Device; onRevoke: (device: Device) => void }) {
    const owned = device.owned_threads[0];
    const system = systemLabel(device.os, device.arch);
    const showLastSeen = device.last_seen_at !== null && device.last_seen_at !== device.last_teleport?.at;

    return (
        <li className="flex flex-wrap items-start gap-3 px-4 py-3.5" data-testid="device-row">
            <span className="grid size-8 shrink-0 place-items-center rounded-control bg-brand-soft text-brand"><Monitor size={15} aria-hidden="true" /></span>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-[13.5px] font-medium">{device.name}</span>
                    {owned && <Badge tone="accent">Has the thread &ldquo;{owned.title}&rdquo;</Badge>}
                    {device.is_stale && <StatusPill tone="warn" label={`Not used in ${device.stale_days} days`} />}
                </div>
                <p className="text-[12px] text-muted">{system} &middot; {device.cli_version ? `yak ${device.cli_version}` : 'yak'} &middot; paired {shortDate(device.paired_at)}</p>
                <p className="text-[12px] text-muted">
                    {device.last_teleport
                        ? <>Last teleport {relative(device.last_teleport.at)}: <Link href={showThread.url(device.last_teleport.thread_id)} className={`rounded-[3px] text-accent-text hover:underline ${FOCUS}`}>{device.last_teleport.title}</Link></>
                        : 'Never teleported'}
                    {showLastSeen && device.last_seen_at && <> &middot; last seen {relative(device.last_seen_at)}</>}
                </p>
                {device.is_stale && <p className="text-[12px] text-warn" data-testid="device-stale-hint">Not used in {device.stale_days} days. Revoke it if this computer is gone.</p>}
            </div>
            <Button variant="secondary" icon={<Trash2 size={13} />} onClick={() => onRevoke(device)} data-testid="device-revoke">Revoke</Button>
        </li>
    );
}

function revokeBody(device: Device | null): ReactNode {
    if (device === null) {
        return '';
    }

    const [owned, ...others] = device.owned_threads;

    if (owned) {
        return <>The thread &ldquo;{owned.title}&rdquo; is on this computer right now.{others.length > 0 && <> Also: {others.map((other) => `\u201c${other.title}\u201d`).join(', ')}.</>} It returns to Yak as it was when it left. Work done on the computer since then stays in that Claude Code session, which becomes a normal local session again, and the thread cannot be teleported from it. To bring that work to Yak, pair the computer again and run <code className="font-mono">yak teleport</code> to start a new thread from the branch. The token stops working at once.</>;
    }

    return <>This computer can no longer teleport. Its token stops working at once, and a teleport in progress there stops with a message that says so. You can pair it again with <code className="font-mono">yak login</code>.</>;
}

export default function Devices({ devices }: Props) {
    const [revoking, setRevoking] = useState<Device | null>(null);
    const [isBusy, setIsBusy] = useState(false);
    const [revokedName, setRevokedName] = useState<string | null>(null);
    const [didLoadFail, setDidLoadFail] = useState(false);

    useEffect(() => {
        const fail = () => setDidLoadFail(true);
        const offs = [router.on('httpException', fail), router.on('networkError', fail)];

        return () => offs.forEach((off) => off());
    }, []);

    const retry = () => {
        setDidLoadFail(false);
        router.reload({ only: ['devices'] });
    };

    const confirmRevoke = () => {
        if (revoking === null) {
            return;
        }

        const target = revoking;
        setIsBusy(true);
        router.delete(destroy.url(target.id), {
            preserveScroll: true,
            onSuccess: () => {
                setRevokedName(target.name);
                setRevoking(null);
            },
            onFinish: () => setIsBusy(false),
        });
    };

    const failedCard = (
        <Card className="p-8" data-testid="devices-load-failed">
            <EmptyState icon={<AlertTriangle size={18} />} title="Could not load your computers" body="Yak could not reach its server, so your list may be out of date. Nothing was changed. Try again in a moment." action={<Button variant="secondary" icon={<RefreshCw size={13} />} onClick={retry}>Try again</Button>} />
        </Card>
    );

    return (
        <>
            <Head title="Devices" />

            <div className="flex flex-col gap-6">
                {revokedName !== null && (
                    <div role="status" className="flex items-start gap-2 rounded-card border border-hair bg-ok-soft px-4 py-3 text-[13px]" data-testid="device-revoked-banner">
                        <Check size={15} className="mt-0.5 shrink-0 text-ok" aria-hidden="true" />
                        <p>Revoked {revokedName}. Its token stopped working right away. To use that computer again, run <code className="font-mono">yak login</code> on it.</p>
                    </div>
                )}

                <section className="flex flex-col gap-3" aria-labelledby="devices-heading">
                    <h3 id="devices-heading" className="text-[14px] font-semibold">Paired computers</h3>
                    {didLoadFail && devices === undefined ? failedCard : (
                        <Deferred data="devices" fallback={<LoadingRows />}>
                            {devices && devices.length > 0
                                ? (
                                    <div className="flex flex-col gap-4">
                                        <Card><ul className="divide-y divide-hair">{devices.map((device) => <DeviceRow key={device.id} device={device} onRevoke={setRevoking} />)}</ul></Card>
                                        <AddComputerCard heading="Add a computer" />
                                    </div>
                                )
                                : <EmptyDevices />}
                        </Deferred>
                    )}
                </section>
            </div>

            <ConfirmDialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                title={`Revoke ${revoking?.name ?? ''}?`}
                body={revokeBody(revoking)}
                confirmLabel="Revoke computer"
                confirmTestId="device-revoke-confirm"
                busy={isBusy}
                onConfirm={confirmRevoke}
            />
        </>
    );
}

Devices.layout = (page: ReactNode) => <SettingsLayout slug="devices">{page}</SettingsLayout>;
