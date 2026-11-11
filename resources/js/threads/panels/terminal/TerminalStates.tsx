import { Button, Spinner } from '@geocodio/console-ui';
import { RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { CommandBlock } from '@/components/threads/teleport/CommandBlock';
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
                <Centered testId="terminal-device" title="This thread is running on a computer.">
                    <p className="max-w-sm text-[13px] text-muted">Teleport it back to Yak to use the sandbox shell. To continue it on another computer:</p>
                    <div className="w-full max-w-md">
                        <CommandBlock command={teleport.command} testId="terminal-teleport-command" />
                    </div>
                </Centered>
            );
    }
}

type Props = {
    state: Exclude<TerminalSessionState, 'connected' | 'idle'>;
    reason: string | null;
    sandboxState: ThreadData['sandboxState'];
    onReconnect: () => void;
};

/** Overlay for every session state except a live shell. */
export function SessionState({ state, reason, sandboxState, onReconnect }: Props) {
    if (state === 'connecting') {
        const waking = sandboxState === 'paused' || sandboxState === 'stopped';

        return (
            <Centered testId="terminal-connecting" title={waking ? 'Waking the sandbox' : 'Connecting to the sandbox'}>
                <Spinner size="md" />
            </Centered>
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

    const title = state === 'lost' ? 'Connection lost' : state === 'denied' ? 'The terminal session was refused' : 'The terminal is unavailable';

    return (
        <Centered testId={`terminal-${state}`} title={title}>
            {reason && <p className="max-w-sm text-[13px] text-muted">{reason}</p>}
            <Button variant="secondary" icon={<RefreshCw size={14} />} onClick={onReconnect} data-testid="terminal-reconnect">
                Reconnect
            </Button>
        </Centered>
    );
}
