import { Button, Spinner } from '@geocodio/console-ui';
import { usePage } from '@inertiajs/react';
import { RefreshCw, WifiOff } from 'lucide-react';
import type { ReactNode } from 'react';
import { CommandBlock } from '@/components/threads/teleport/CommandBlock';
import type { SharedProps } from '@/types/shared';
import type { TeleportData, ThreadData } from '@/types/threads';
import type { TerminalSessionState } from './useTerminalSession';

function Centered({ testId, title, children }: { testId: string; title: string; children?: ReactNode }) {
    return (
        <div className="flex h-full flex-col items-center justify-center gap-3 px-6 text-center" data-testid={testId}>
            <p className="text-[14px] font-medium text-body">{title}</p>
            {children}
        </div>
    );
}

/** Why no shell is offered for this thread, or null when one can be opened. */
export function blockedState(thread: ThreadData, canDrive: boolean): 'viewer' | 'closed' | 'device' | 'destroyed' | null {
    if (thread.status === 'closed') {
        return 'closed';
    }
    if (thread.ownerSide === 'device') {
        return 'device';
    }
    if (!canDrive) {
        return 'viewer';
    }
    if (thread.sandboxState === 'destroyed' || thread.sandboxState === 'destroying') {
        return 'destroyed';
    }

    return null;
}

export function BlockedState({ kind, teleport }: { kind: NonNullable<ReturnType<typeof blockedState>>; teleport: TeleportData }) {
    switch (kind) {
        case 'viewer':
            return <Centered testId="terminal-viewer" title="Only people who can drive this thread can open a shell." />;
        case 'closed':
            return <Centered testId="terminal-closed" title="This thread is closed, so its sandbox has no shell." />;
        case 'destroyed':
            return (
                <Centered testId="terminal-destroyed" title="The sandbox was destroyed.">
                    <p className="max-w-sm text-[13px] text-muted">Restore it from the thread page to get a shell again.</p>
                </Centered>
            );
        case 'device':
            return (
                <Centered testId="terminal-device" title="This thread runs on your computer">
                    <p className="max-w-sm text-[13px] text-muted">Claude is working in a folder on your computer, so there is no sandbox to open a shell in.</p>
                    <p className="max-w-sm text-[13px] text-muted">Teleport the thread and the terminal appears here.</p>
                    {teleport.command !== '' && (
                        <div className="w-full max-w-md">
                            <CommandBlock command={teleport.command} testId="terminal-teleport-command" />
                        </div>
                    )}
                </Centered>
            );
    }
}

type Props = {
    state: Exclude<TerminalSessionState, 'connected' | 'idle' | 'lost'>;
    reason: string | null;
    sandboxState: ThreadData['sandboxState'];
    onReconnect: () => void;
};

/** Overlay for every session state except a live shell. */
export function SessionState({ state, reason, sandboxState, onReconnect }: Props) {
    if (state === 'connecting') {
        const waking = sandboxState === 'paused' || sandboxState === 'stopped';

        return (
            <div className="h-full overflow-auto bg-panel-2 px-3 py-2.5 font-mono text-[12.5px] leading-[1.55]" data-testid="terminal-connecting" aria-live="polite">
                <div className="flex items-center gap-2 text-muted">
                    <Spinner size="sm" />
                    {waking ? 'Waking the sandbox...' : 'Connecting to the sandbox...'}
                </div>
                {waking && <div className="text-faint">The sandbox was paused. Opening a terminal wakes it and counts toward the awake limit.</div>}
            </div>
        );
    }
    if (state === 'waiting') {
        return (
            <Centered testId="terminal-waiting" title="Waiting for a free sandbox">
                <Spinner size="md" />
                <p className="max-w-sm text-[13px] text-muted">Other sandboxes are using every slot. This opens as soon as one is free.</p>
            </Centered>
        );
    }

    const title = state === 'denied' ? 'The terminal session was refused' : 'The terminal is unavailable';

    return (
        <Centered testId={`terminal-${state}`} title={title}>
            {reason && <p className="max-w-sm text-[13px] text-muted">{reason}</p>}
            <Button variant="secondary" icon={<RefreshCw size={14} />} onClick={onReconnect} data-testid="terminal-reconnect">
                Reconnect
            </Button>
        </Centered>
    );
}

/** The shell is still drawn below; only the link to it dropped. */
export function LostBanner({ onReconnect }: { onReconnect: () => void }) {
    const docsUrl = usePage<SharedProps>().props.docs.baseUrl;

    return (
        <div className="m-2.5 flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-hair bg-panel-2 px-3 py-2.5" role="alert" data-testid="terminal-lost">
            <WifiOff size={14} className="shrink-0 text-fail" aria-hidden="true" />
            <div className="min-w-0 flex-1 basis-60">
                <p className="text-[13px] font-medium text-body">The terminal lost its connection</p>
                <p className="text-[12.5px] text-muted">Reconnect to open a new shell. Anything you typed while disconnected was not sent.</p>
            </div>
            <Button variant="primary" icon={<RefreshCw size={14} />} onClick={onReconnect} data-testid="terminal-reconnect">
                Reconnect
            </Button>
            <a href={docsUrl} target="_blank" rel="noreferrer" className="text-[12.5px] text-accent-text hover:underline">
                Why this happens
            </a>
        </div>
    );
}
