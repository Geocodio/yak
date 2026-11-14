import { Button, Spinner } from '@geocodio/console-ui';
import { usePage } from '@inertiajs/react';
import { Check, RefreshCw, WifiOff, X } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { CommandBlock } from '@/components/threads/teleport/CommandBlock';
import type { SharedProps } from '@/types/shared';
import type { TeleportData, ThreadData } from '@/types/threads';
import { WAIT_LIMIT_MS, type TerminalLimit, type TerminalPhase, type TerminalSessionState } from './useTerminalSession';

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

/** Seconds a paused sandbox may take to wake before the wait counts as slow. */
export const SLOW_WAKE_PAUSED_SECONDS = 8;
/** Seconds a stopped sandbox may take to start before the wait counts as slow. */
export const SLOW_WAKE_STOPPED_SECONDS = 30;

const ART = ['  __   __     _', '  \\ \\ / /_ _ | | __', '   \\ V / _` || |/ /', '    | | (_| ||   <', '    |_|\\__,_||_|\\_\\'].join('\n');

const STEPS: { phase: TerminalPhase; name: string; todo: string }[] = [
    { phase: 'sandbox', name: 'sandbox', todo: 'sandbox' },
    { phase: 'link', name: 'link', todo: 'secure connection' },
    { phase: 'shell', name: 'shell', todo: '/workspace' },
];

type StepStatus = 'done' | 'active' | 'todo' | 'fail';

function StepRow({ status, name, text, seconds, slow = false }: { status: StepStatus; name: string; text: string; seconds?: string; slow?: boolean }) {
    const tone = status === 'fail' ? 'text-fail' : status === 'done' ? 'text-muted' : status === 'active' ? 'text-body' : 'text-faint';

    return (
        <div className={`flex min-h-[18px] items-start ${tone}`} data-testid={`terminal-step-${name}`} data-status={status}>
            <span className="flex h-[18px] w-[2ch] shrink-0 items-center">
                {status === 'active' && <Spinner size="sm" />}
                {status === 'done' && <Check size={12} className="text-ok" aria-hidden="true" />}
                {status === 'fail' && <X size={12} aria-hidden="true" />}
                {status === 'todo' && <span className="ml-[3px] size-[5px] rounded-pill border border-faint" aria-hidden="true" />}
            </span>
            <span className="w-[9ch] shrink-0 text-faint">{name}</span>
            <span className="min-w-0">
                {text}
                {status === 'active' && <span className="activity-pulse ml-1.5 inline-block h-3.5 w-[7px] bg-muted align-[-2px]" aria-hidden="true" />}
                {seconds !== undefined && <span className={`tnum ml-[2ch] whitespace-nowrap ${slow ? 'text-warn' : 'text-faint'}`}>{seconds}</span>}
            </span>
        </div>
    );
}

/** The terminal surface while nothing is connected: the welcome splash as an outline, then the step rows under it. */
function Surface({ moving, children }: { moving: boolean; children: ReactNode }) {
    return (
        <div className="h-full overflow-auto bg-panel-2 p-2 font-mono text-[13px] leading-[18px]">
            <pre className={`mb-[18px] w-max select-none opacity-60 ${moving ? 'activity-sheen' : 'text-faint'}`} aria-hidden="true">
                {ART}
            </pre>
            {children}
        </div>
    );
}

function Callout({ testId, title, children }: { testId: string; title: string; children: ReactNode }) {
    return (
        <div className="activity-rise mt-[18px] max-w-[62ch] rounded-card bg-fail-soft px-3.5 py-3 font-sans" role="alert" data-testid={testId}>
            <p className="flex items-center gap-[7px] text-[13.5px] font-medium text-body">
                <span className="size-2 shrink-0 rounded-pill bg-fail" aria-hidden="true" />
                {title}
            </p>
            {children}
        </div>
    );
}

function secondsLabel(milliseconds: number): string {
    return `${(milliseconds / 1000).toFixed(1)}s`;
}

function clockLabel(milliseconds: number): string {
    const total = Math.floor(milliseconds / 1000);

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')} of ${Math.floor(WAIT_LIMIT_MS / 60000)}:00`;
}

const LIMIT_COPY: Record<TerminalLimit, string> = {
    person_share: 'You have reached your limit of awake sandboxes.',
    global_cap: 'Every sandbox slot on this server is in use.',
    memory_floor: 'The server is low on memory.',
};

type WaitProps = {
    state: 'connecting' | 'waiting';
    phase: TerminalPhase;
    limit: TerminalLimit | null;
    personAwakeCap: number;
    sandboxState: ThreadData['sandboxState'];
    onReconnect: () => void;
};

/** The wait before the first bytes: three steps backed by the session request, the socket and the shell output. */
function WaitingState({ state, phase, limit, personAwakeCap, sandboxState, onReconnect }: WaitProps) {
    const starts = useRef<Partial<Record<TerminalPhase, number>>>({});
    const [now, setNow] = useState(() => Date.now());
    starts.current[phase] ??= Date.now();

    useEffect(() => {
        const interval = setInterval(() => setNow(Date.now()), 200);

        return () => clearInterval(interval);
    }, []);

    const isWaitingForSlot = state === 'waiting';
    const isCold = sandboxState === 'stopped';
    const isWaking = isCold || sandboxState === 'paused';
    const slowAfter = isCold ? SLOW_WAKE_STOPPED_SECONDS : SLOW_WAKE_PAUSED_SECONDS;
    const sandboxMilliseconds = now - (starts.current.sandbox ?? now);
    const isSlow = !isWaitingForSlot && phase === 'sandbox' && isWaking && sandboxMilliseconds >= slowAfter * 1000;
    const activeIndex = STEPS.findIndex((step) => step.phase === phase);

    const activeText: Record<TerminalPhase, string> = {
        sandbox: isWaitingForSlot ? 'waiting for a free slot' : isSlow ? 'still waking' : isCold ? 'starting from a full stop' : isWaking ? 'waking' : 'checking',
        link: 'opening a secure connection',
        shell: 'starting in /workspace',
    };

    const rows = STEPS.map((step, index) => {
        const status: StepStatus = index < activeIndex ? 'done' : index === activeIndex ? 'active' : 'todo';
        const begun = starts.current[step.phase];
        const next = starts.current[STEPS[index + 1]?.phase];
        const text = status === 'active' ? activeText[step.phase] : status === 'done' ? (index === 0 ? 'awake' : 'connected') : step.todo;
        let seconds: string | undefined;
        if (begun !== undefined && status !== 'todo') {
            seconds = isWaitingForSlot && status === 'active' ? clockLabel(now - begun) : secondsLabel((next ?? now) - begun);
        }

        return <StepRow key={step.phase} status={status} name={step.name} text={text} seconds={seconds} slow={isSlow && status === 'active'} />;
    });

    const title = isCold ? 'Starting the sandbox' : 'Waking the sandbox';

    return (
        <Surface moving>
            <div aria-live="polite" data-testid={isWaitingForSlot ? 'terminal-waiting' : 'terminal-connecting'}>
                <p className="sr-only">{isWaitingForSlot ? 'Waiting for a free sandbox' : title}</p>
                {rows}
                {isWaitingForSlot && (
                    <div className="ml-[11ch] mt-[7px] h-0.5 w-[min(28ch,60%)] overflow-hidden rounded-sm bg-hair" aria-hidden="true">
                        <div className="h-full bg-accent" style={{ width: `${Math.min(100, ((now - (starts.current.sandbox ?? now)) / WAIT_LIMIT_MS) * 100)}%` }} />
                    </div>
                )}
            </div>
            {isWaitingForSlot && limit !== null && (
                <div className="activity-rise mt-[18px] max-w-[62ch] rounded-card bg-warn-soft px-3.5 py-3 font-sans" data-testid="terminal-limit">
                    <p className="flex items-center gap-[7px] text-[13.5px] font-medium text-body">
                        <span className="size-2 shrink-0 rounded-pill bg-warn" aria-hidden="true" />
                        {limit === 'person_share' ? `You have reached your limit of ${personAwakeCap} awake sandboxes` : LIMIT_COPY[limit]}
                    </p>
                    <p className="ml-[15px] mt-0.5 text-[13px] text-muted">
                        {limit === 'person_share' ? 'This terminal opens when one of your sandboxes pauses. Pause or close a thread you are not using to get a slot sooner.' : 'This terminal opens on its own as soon as a slot frees up.'}
                    </p>
                </div>
            )}
            {!isWaitingForSlot && (
                <div className="activity-rise ml-[2ch] mt-[18px] max-w-[58ch] font-sans text-[12.5px] text-muted">
                    <p>{isSlow ? 'This is taking longer than usual. You can keep waiting, or start again.' : title}</p>
                    {isSlow && (
                        <Button variant="secondary" icon={<RefreshCw size={14} />} onClick={onReconnect} className="mt-2.5" data-testid="terminal-retry">
                            Try again
                        </Button>
                    )}
                </div>
            )}
        </Surface>
    );
}

type Props = {
    state: Exclude<TerminalSessionState, 'connected' | 'idle' | 'lost'>;
    phase: TerminalPhase;
    limit: TerminalLimit | null;
    reason: string | null;
    sandboxState: ThreadData['sandboxState'];
    personAwakeCap: number;
    onReconnect: () => void;
};

/** Overlay for every session state except a live shell. */
export function SessionState({ state, phase, limit, reason, sandboxState, personAwakeCap, onReconnect }: Props) {
    if (state === 'connecting' || state === 'waiting') {
        return <WaitingState state={state} phase={phase} limit={limit} personAwakeCap={personAwakeCap} sandboxState={sandboxState} onReconnect={onReconnect} />;
    }

    const failedIndex = STEPS.findIndex((step) => step.phase === phase);
    const isDenied = state === 'denied';

    return (
        <Surface moving={false}>
            <div data-testid={`terminal-${state}`}>
                {STEPS.map((step, index) => {
                    const status: StepStatus = index < failedIndex ? 'done' : index === failedIndex ? 'fail' : 'todo';
                    const text = status === 'fail' ? (isDenied ? 'refused' : 'unavailable') : status === 'done' ? (index === 0 ? 'awake' : 'connected') : step.todo;

                    return <StepRow key={step.phase} status={status} name={step.name} text={text} />;
                })}
                <Callout testId={`terminal-${state}-callout`} title={isDenied ? 'The connection was refused' : 'The terminal could not open'}>
                    {reason && <p className="ml-[15px] mt-0.5 text-[13px] text-muted">{reason}</p>}
                    <div className="ml-[15px] mt-2.5">
                        <Button variant="primary" icon={<RefreshCw size={14} />} onClick={onReconnect} data-testid="terminal-reconnect">
                            Try again
                        </Button>
                    </div>
                </Callout>
            </div>
        </Surface>
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
