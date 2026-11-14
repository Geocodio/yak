import { StatusPill, type StatusPillProps } from '@geocodio/console-ui';
import type { ThreadData } from '@/types/threads';
import type { TerminalSessionState } from './useTerminalSession';

const PILLS: Record<TerminalSessionState, { tone: StatusPillProps['tone']; label: string }> = {
    idle: { tone: 'idle', label: 'Not connected' },
    connecting: { tone: 'warn', label: 'Connecting' },
    waiting: { tone: 'warn', label: 'Waiting' },
    connected: { tone: 'ok', label: 'Connected' },
    lost: { tone: 'fail', label: 'Disconnected' },
    denied: { tone: 'fail', label: 'Refused' },
    unavailable: { tone: 'idle', label: 'Unavailable' },
};

export function TerminalStatus({ state, sandboxState }: { state: TerminalSessionState; sandboxState?: ThreadData['sandboxState'] }) {
    const wakingLabel = sandboxState === 'paused' ? 'Waking' : sandboxState === 'stopped' ? 'Starting' : null;
    const pill = state === 'connecting' && wakingLabel !== null ? { ...PILLS.connecting, label: wakingLabel } : PILLS[state];

    return (
        <span data-testid="terminal-status" data-state={state}>
            <StatusPill tone={pill.tone} label={pill.label} pulse={state === 'connecting' || state === 'waiting'} />
        </span>
    );
}
