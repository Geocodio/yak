import { cn } from '@geocodio/console-ui';
import type { PanelProps } from '@/threads/panels';
import type { ThreadEnvironment } from '@/types/thread-changes';
import { CapacityBlock } from './CapacityBlock';
import { OverviewGroup } from './OverviewRow';
import { StatusCard } from './StatusCard';

type SandboxState = ThreadEnvironment['sandbox']['state'];

const DOT: Record<SandboxState, string> = {
    running: 'bg-ok',
    starting: 'bg-info',
    paused: 'bg-warn',
    stopped: 'bg-idle',
    destroying: 'bg-idle',
    destroyed: 'bg-idle',
    none: 'bg-idle',
};

const LABEL: Record<SandboxState, string> = {
    running: 'Running',
    starting: 'Starting',
    paused: 'Paused',
    stopped: 'Stopped',
    destroying: 'Being removed',
    destroyed: 'Removed',
    none: 'No sandbox yet',
};

function sandboxSentence(state: SandboxState, stoppedAfterMinutes: number, isWorking: boolean): string {
    switch (state) {
        case 'running':
            return isWorking ? `Yak is working in it now. Sleeps after ${stoppedAfterMinutes} idle minutes.` : 'The sandbox is awake and ready.';
        case 'starting':
            return 'The sandbox is starting.';
        case 'paused':
            return `Wakes in about a second when you send a message. Stops on its own after ${stoppedAfterMinutes} idle minutes.`;
        case 'stopped':
            return 'The sandbox is stopped. Sending a message rebuilds it from the thread history.';
        case 'destroying':
            return 'The sandbox is being removed.';
        case 'destroyed':
            return 'The sandbox was removed. Sending a message rebuilds it from the thread history.';
        case 'none':
            return 'A sandbox is created when you send the first message.';
    }
}

/** The first environment group: the rebuild or waiting card, the capacity meters, and the sandbox state. */
export function StatusGroup({ thread, notice, composerState, capacity = null, accountName = null, environmentSwitch = null, environment }: PanelProps & { environment: ThreadEnvironment | null }) {
    const isWaiting = notice?.kind === 'waiting_for_account' || notice?.kind === 'waiting_for_sandbox';
    const hasCapacity = isWaiting || composerState === 'working';

    return (
        <OverviewGroup>
            <div className="flex flex-col gap-3">
                <StatusCard thread={thread} notice={notice} composerState={composerState} />
                {hasCapacity && <CapacityBlock capacity={capacity} accountName={accountName} />}
                {environment !== null && (
                    <div data-testid="environment-sandbox-state">
                        <div className="flex items-center gap-2 text-[13px] font-medium text-body">
                            <span className={cn('inline-block size-1.5 rounded-pill', DOT[environment.sandbox.state])} aria-hidden />
                            Sandbox {LABEL[environment.sandbox.state].toLowerCase()}
                        </div>
                        <p className="mt-0.5 text-[12px] text-muted">{sandboxSentence(environment.sandbox.state, environment.stoppedAfterMinutes, composerState === 'working')}</p>
                    </div>
                )}
                {environmentSwitch?.to === 'Yak' && (
                    <p className="text-[12px] text-muted" data-testid="environment-built-note">
                        Built today at {new Date(environmentSwitch.at).toLocaleTimeString(undefined, { timeStyle: 'short' })} from your computer. See the note.
                    </p>
                )}
            </div>
        </OverviewGroup>
    );
}
