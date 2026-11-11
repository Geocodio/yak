import { Link, router } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { teleportComposerHint } from '@/components/threads/composer/composerCopy';
import { formatTime } from '@/components/shell/formatTime';
import { destroy, store } from '@/routes/threads/teleport-request';
import { show } from '@/routes/threads';
import type { TeleportData, ThreadData } from '@/types/threads';

/** The name shown for the machine that owns the thread. */
export function ownerLabel(teleport: TeleportData): string {
    return teleport.ownerDevice?.name ?? teleport.ownerName ?? 'another computer';
}

function Banner({ tone, children }: { tone: 'info' | 'warning'; children: React.ReactNode }) {
    return (
        <div
            className={`flex flex-wrap items-center justify-between gap-3 rounded-lg px-4 py-3 text-[13px] ${tone === 'warning' ? 'bg-warn-soft' : 'bg-accent-soft'}`}
            data-testid="teleport-notice"
        >
            {children}
        </div>
    );
}

export function TeleportNotice({ thread, teleport }: { thread: ThreadData; teleport: TeleportData }) {
    const since = formatTime(teleport.since);
    const cancel = () => router.delete(destroy.url(thread.id), { preserveScroll: true });
    const retry = () => router.post(store.url(thread.id), {}, { preserveScroll: true });
    const continuedFrom =
        teleport.continuedFromThreadId !== null ? (
            <Link href={show.url(teleport.continuedFromThreadId)} className="text-accent-text hover:underline" data-testid="teleport-continued-from">
                Continued from {teleport.continuedFromTitle ?? `thread ${teleport.continuedFromThreadId}`}
            </Link>
        ) : null;

    if (teleport.state === 'waiting') {
        return (
            <Banner tone="info">
                <span>
                    <strong className="font-medium">Waiting for your computer</strong>
                    {since && ` since ${since}`}. {teleportComposerHint.waiting}
                </span>
                <Button variant="tertiary" onClick={cancel}>Cancel teleport</Button>
            </Banner>
        );
    }

    if (teleport.state === 'teleporting') {
        return (
            <Banner tone="info">
                <span>
                    <strong className="font-medium">Teleporting</strong>
                    {since && ` since ${since}`}. Yak is saving the work and handing the thread over.
                </span>
                <Button variant="tertiary" onClick={cancel}>Cancel teleport</Button>
            </Banner>
        );
    }

    if (teleport.state === 'on_device') {
        return (
            <Banner tone="info">
                <span>
                    <strong className="font-medium">On {ownerLabel(teleport)}</strong>
                    {since && ` since ${since}`}.{' '}
                    {teleport.viewerIsOwner ? 'You are working on this.' : `${teleport.ownerName ?? 'Its owner'} is working on this.`}
                    {thread.visibility === 'private' && ' Private to you.'}
                </span>
                {continuedFrom}
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
