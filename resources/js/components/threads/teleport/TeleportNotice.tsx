import { Link, router } from '@inertiajs/react';
import { Button, ConfirmDialog } from '@geocodio/console-ui';
import { CloudUpload, Hourglass, Laptop, Square, Terminal } from 'lucide-react';
import { useState } from 'react';
import { timeAgo } from '@/components/settings/ssh/KeyRow';
import { TELEPORT_DOCS } from '@/components/threads/teleport/TeleportToYakDialog';
import { formatTime } from '@/components/shell/formatTime';
import { destroy, store } from '@/routes/threads/teleport-request';
import { show, stop } from '@/routes/threads';
import type { TeleportData, ThreadData } from '@/types/threads';

/** Composer copy for W2-THREADPAGE. */
export const teleportComposerHint = {
    waiting: 'Yak keeps working until you run the command on your computer.',
    onDevice: (name: string) => `This thread is on ${name}. Teleport it to Yak to message Claude here.`,
};

/** The name shown for the machine that owns the thread. */
export function ownerLabel(teleport: TeleportData): string {
    return teleport.ownerDevice?.name ?? (teleport.ownerName ? `${teleport.ownerName.split(' ')[0]}'s computer` : 'another computer');
}

function Banner({ tone, icon, title, children, actions }: { tone: 'info' | 'warning'; icon?: React.ReactNode; title?: string; children: React.ReactNode; actions?: React.ReactNode }) {
    return (
        <div
            className={`flex flex-wrap items-center justify-between gap-3 rounded-lg px-4 py-3 text-[13px] ${tone === 'warning' ? 'bg-warn-soft' : 'bg-accent-soft'}`}
            data-testid="teleport-notice"
        >
            <div className="flex min-w-0 flex-1 items-start gap-3">
                {icon && <span className="mt-0.5 shrink-0">{icon}</span>}
                <div className="min-w-0">
                    {title && <p className="font-medium text-body">{title}</p>}
                    <div className={title ? 'mt-0.5 text-muted' : ''}>{children}</div>
                </div>
            </div>
            {actions && <div className="flex items-center gap-2">{actions}</div>}
        </div>
    );
}

function firstName(teleport: TeleportData): string {
    return (teleport.ownerName ?? 'its owner').split(' ')[0];
}

export function TeleportNotice({
    thread,
    teleport,
    onShowCommand,
    onTeleportToYak,
}: {
    thread: ThreadData;
    teleport: TeleportData;
    onShowCommand: () => void;
    onTeleportToYak: () => void;
}) {
    const [confirmingStop, setConfirmingStop] = useState(false);
    const since = formatTime(teleport.since);
    const cancel = () => router.delete(destroy.url(thread.id), { preserveScroll: true });
    const retry = () => router.post(store.url(thread.id), {}, { preserveScroll: true });
    const stopNow = () => router.post(stop.url(thread.id), {}, { preserveScroll: true, onFinish: () => setConfirmingStop(false) });
    const continuedFrom =
        teleport.continuedFromThreadId !== null ? (
            <Link href={show.url(teleport.continuedFromThreadId)} className="text-accent-text hover:underline" data-testid="teleport-continued-from">
                Continued from thread {teleport.continuedFromThreadId}
            </Link>
        ) : null;

    if (teleport.state === 'waiting') {
        return (
            <Banner
                tone="info"
                icon={<Hourglass size={16} />}
                title="Waiting for a computer"
                actions={
                    <>
                        <Button variant="primary" icon={<Terminal size={14} />} onClick={onShowCommand} data-testid="teleport-show-command">Show command</Button>
                        <Button variant="tertiary" onClick={cancel}>Cancel teleport</Button>
                    </>
                }
            >
                Run <code className="font-mono">{teleport.command}</code> in your checkout of {thread.repo}. Yak keeps the thread until then. Messages you send now are included when it moves.
            </Banner>
        );
    }

    if (teleport.state === 'teleporting') {
        return (
            <>
                <Banner
                    tone="warning"
                    icon={<Laptop size={16} />}
                    title={`${teleport.ownerDevice?.name ?? 'A computer'} is waiting for this turn to finish`}
                    actions={
                        <>
                            <Button variant="secondary" icon={<Square size={14} />} onClick={() => setConfirmingStop(true)} data-testid="teleport-stop-now">Stop now and teleport</Button>
                            <Button variant="tertiary" onClick={cancel}>Cancel teleport</Button>
                        </>
                    }
                >
                    You ran <code className="font-mono">{teleport.command}</code>. Claude is finishing its turn, started {since ? timeAgo(teleport.since as string) : 'a moment ago'}, and the thread moves on its own after that. Stopping now keeps the work done so far.
                </Banner>
                <ConfirmDialog
                    open={confirmingStop}
                    onOpenChange={setConfirmingStop}
                    title="Stop now and teleport?"
                    body="Claude stops mid-turn and the thread moves to the computer. The work done so far is kept."
                    confirmLabel="Stop now and teleport"
                    confirmTestId="teleport-stop-now-confirm"
                    onConfirm={stopNow}
                />
            </>
        );
    }

    if (teleport.state === 'on_device') {
        const privateSuffix = thread.visibility === 'private' && ' Private to you.';

        return (
            <Banner
                tone="info"
                icon={<Laptop size={16} />}
                title={teleport.viewerIsOwner ? `On ${ownerLabel(teleport)}${since ? ` since ${since}` : ''}` : `${teleport.ownerName ?? 'Its owner'} has this on their computer`}
                actions={
                    <>
                        {continuedFrom}
                        {teleport.viewerIsOwner && (
                            <Button variant="primary" icon={<CloudUpload size={14} />} onClick={onTeleportToYak} data-testid="teleport-up-banner">Teleport to Yak</Button>
                        )}
                    </>
                }
            >
                {teleport.viewerIsOwner ? (
                    <>
                        You are working on this in Claude Code. This page shows the thread as it was when it left Yak. New work appears here after you teleport it back.{' '}
                        <a href={TELEPORT_DOCS} className="text-accent-text hover:underline" target="_blank" rel="noreferrer">How teleport works</a>
                    </>
                ) : (
                    `${teleport.ownerName ?? 'Its owner'} is working on this in Claude Code${since ? ` since ${since}` : ''}. This page shows the thread as it was when it left Yak. Only ${firstName(teleport)} can teleport it back.`
                )}
                {privateSuffix}
            </Banner>
        );
    }

    if (teleport.failure !== null) {
        return (
            <Banner tone="warning">
                <span>
                    <strong className="font-medium">Teleport failed.</strong> {teleport.failure.message}
                </span>
                <Button variant="secondary" onClick={retry}>Retry</Button>
            </Banner>
        );
    }

    return continuedFrom === null ? null : <Banner tone="info">{continuedFrom}</Banner>;
}
