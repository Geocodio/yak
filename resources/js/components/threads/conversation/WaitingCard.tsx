import { Hourglass, Users } from 'lucide-react';
import { cn } from '@geocodio/console-ui';
import type { ThreadNotice, ThreadWaiting } from '@/types/threads';
import { useDocsUrl } from '@/lib/docsUrl';
import { CapacityMeter } from './CapacityMeter';


function AccountBody({ waiting }: { waiting: ThreadWaiting }) {
    const docsUrl = useDocsUrl('threads/');
    const { accountName, turnsLimit, turnsRunning, position } = waiting;
    const queued = position !== null && position > 1;
    const waitingCount = Math.max(position ?? 1, 1);

    return (
        <>
            <p className="mt-1 text-[13px] text-muted">
                <strong className="font-medium text-body">{accountName}</strong> is already running its {turnsLimit} turns at once, which is the most one account runs. Yak starts your turn the moment one of them finishes.{' '}
                {queued ? <strong className="font-medium text-body">{position} in line</strong> : <>It is <strong className="font-medium text-body">next in line</strong></>}, and your message is saved.
            </p>
            <div className="mt-3 rounded-card bg-app p-3">
                <CapacityMeter label="Turns on this account" used={turnsRunning} limit={turnsLimit} blocking>
                    {turnsRunning} of {turnsLimit} running, {waitingCount} waiting
                </CapacityMeter>
                <p className="mt-2 text-[12px] text-muted">Only account admins can change this limit.</p>
            </div>
            <a href={docsUrl} target="_blank" rel="noreferrer" className="mt-3 inline-block text-[13px] text-accent-text hover:underline">
                About account limits
            </a>
        </>
    );
}

function SlotStrip({ waiting }: { waiting: ThreadWaiting }) {
    return (
        <ul className="mt-2 flex flex-wrap gap-1.5" data-testid="waiting-slots">
            {waiting.slots.map((slot, index) => (
                <li
                    key={index}
                    data-state={slot.state}
                    className={cn(
                        'flex h-7 min-w-7 items-center justify-center rounded-md px-1.5 text-[11px]',
                        slot.state === 'running' ? 'bg-ok text-white' : 'border border-hair bg-panel-2 text-muted',
                        slot.isMine && 'ring-1 ring-accent',
                    )}
                >
                    {slot.state === 'paused' && slot.idleMinutes !== null ? `${slot.idleMinutes}m` : ''}
                </li>
            ))}
        </ul>
    );
}

function SandboxBody({ waiting }: { waiting: ThreadWaiting }) {
    const docsUrl = useDocsUrl('threads/');
    const awake = waiting.slots.length;

    return (
        <>
            <p className="mt-1 text-[13px] text-muted">
                All {awake} sandboxes are awake. Yak stops a paused one after 5 idle minutes. It starts this one soon. Your message is saved.
            </p>
            <div className="mt-3 rounded-card bg-app p-3">
                <div className="flex items-center justify-between text-[13px]">
                    <span className="text-body">Awake sandboxes</span>
                    <span className="text-muted">{awake} of {awake}</span>
                </div>
                <SlotStrip waiting={waiting} />
                <p className="mt-2 flex gap-4 text-[12px] text-muted">
                    <span>Running a turn</span>
                    <span>Paused, with minutes idle</span>
                </p>
            </div>
            <a href={docsUrl} target="_blank" rel="noreferrer" className="mt-3 inline-block text-[13px] text-accent-text hover:underline">
                How sandbox capacity works
            </a>
        </>
    );
}

function ShareBody({ waiting }: { waiting: ThreadWaiting }) {
    const docsUrl = useDocsUrl('threads/');
    const mine = waiting.slots.filter((slot) => slot.isMine);

    return (
        <>
            <p className="mt-1 text-[13px] text-muted">
                You already have <strong className="font-medium text-body">{waiting.ownSlotsUsed} of {waiting.ownSlotsCap}</strong> awake sandboxes, counting your threads and their sub-threads. The limit keeps one person from using up all of them, so this thread waits even though one slot may be free. It starts when one of yours goes idle for 5 minutes.
            </p>
            <div className="mt-3 rounded-card bg-app p-3">
                <p className="text-[12px] font-medium text-body">Your {mine.length} awake sandboxes</p>
                <ul className="mt-1 divide-y divide-hair" data-testid="waiting-own-threads">
                    {mine.map((slot, index) => (
                        <li key={index} className="flex items-center justify-between gap-3 py-2 text-[13px]">
                            <span className="text-body">{slot.state === 'running' ? 'Running a turn' : 'Paused'}</span>
                            <span className="text-muted">{slot.state === 'paused' && slot.idleMinutes !== null ? `Paused, idle ${slot.idleMinutes}m` : 'Running'}</span>
                        </li>
                    ))}
                </ul>
            </div>
            <a href={docsUrl} target="_blank" rel="noreferrer" className="mt-3 inline-block text-[13px] text-accent-text hover:underline">
                Why there is a share
            </a>
        </>
    );
}

/** The conversation card for waiting_for_account and waiting_for_sandbox, with its capacity meters. */
export function WaitingCard({ notice }: { notice: ThreadNotice; threadId: string; readonly?: boolean }) {
    const waiting = notice.waiting;
    const isAccount = notice.kind === 'waiting_for_account';
    const isShare = !isAccount && waiting?.ownSlotsCap != null;
    const Icon = isAccount ? Users : Hourglass;

    return (
        <div className="rounded-card bg-info-soft p-4" data-testid="thread-notice" data-kind={notice.kind}>
            <div className="flex items-start gap-3">
                <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-pill bg-panel text-info">
                    <Icon className="size-4" aria-hidden />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium text-body">{isShare ? 'Waiting for your share of sandboxes' : notice.title}</p>
                    {waiting === null ? (
                        <p className="mt-1 text-[13px] text-muted">{notice.body}</p>
                    ) : isAccount ? (
                        <AccountBody waiting={waiting} />
                    ) : isShare ? (
                        <ShareBody waiting={waiting} />
                    ) : (
                        <SandboxBody waiting={waiting} />
                    )}
                </div>
            </div>
        </div>
    );
}
