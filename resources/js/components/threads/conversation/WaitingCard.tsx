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

type Slot = ThreadWaiting['slots'][number];

function minutes(count: number): string {
    return count === 1 ? '1 minute' : `${count} minutes`;
}

/** A paused sandbox with no recorded activity, or idle past the grace, may be stopped for a waiting thread. */
function isStoppable(slot: Slot, grace: number): boolean {
    return slot.state === 'paused' && (slot.idleMinutes === null || slot.idleMinutes >= grace);
}

function slotTitle(slot: Slot, grace: number): string {
    if (slot.state === 'running') {
        return 'Running a turn';
    }

    if (slot.idleMinutes === null) {
        return 'Paused, no activity recorded, so it can be stopped';
    }

    return slot.idleMinutes >= grace
        ? `Paused, idle ${minutes(slot.idleMinutes)}. Past the ${grace} minute limit, so it can be stopped.`
        : `Paused, idle ${minutes(slot.idleMinutes)}. Can be stopped in ${minutes(grace - slot.idleMinutes)}.`;
}

function SlotStrip({ waiting, freeCount }: { waiting: ThreadWaiting; freeCount: number }) {
    const grace = waiting.idleGraceMinutes;

    return (
        <ul className="mt-2 flex flex-wrap gap-1.5" data-testid="waiting-slots">
            {waiting.slots.map((slot, index) => {
                const stoppable = isStoppable(slot, grace);
                const progress = slot.idleMinutes === null ? 100 : Math.min(100, (slot.idleMinutes / Math.max(grace, 1)) * 100);

                return (
                    <li
                        key={index}
                        data-state={slot.state}
                        data-stoppable={stoppable || undefined}
                        title={slotTitle(slot, grace)}
                        className={cn(
                            'relative flex h-7 min-w-7 items-center justify-center overflow-hidden rounded-md px-1.5 text-[11px] tabular-nums',
                            slot.state === 'running' && 'bg-ok text-white',
                            slot.state === 'paused' && !stoppable && 'border border-hair bg-panel-2 text-muted',
                            stoppable && 'bg-warn-soft font-medium text-warn',
                            slot.isMine && 'ring-1 ring-accent',
                        )}
                    >
                        {slot.state === 'running' ? (
                            <span className="size-2 rounded-pill bg-white/85" aria-hidden />
                        ) : (
                            <>
                                {slot.idleMinutes !== null ? `${slot.idleMinutes}m` : '–'}
                                <span className={cn('absolute bottom-0 left-0 h-[3px]', stoppable ? 'bg-warn' : 'bg-muted/45')} style={{ width: `${progress}%` }} aria-hidden />
                            </>
                        )}
                    </li>
                );
            })}
            {Array.from({ length: freeCount }, (_, index) => (
                <li key={`free-${index}`} data-state="free" title="Free slot" className="h-7 min-w-7 rounded-md border border-dashed border-hair" />
            ))}
        </ul>
    );
}

function Swatch({ className }: { className: string }) {
    return <i className={cn('inline-block size-3 shrink-0 rounded-[3px]', className)} aria-hidden />;
}

/** When the waiting thread is likely to start, read from the paused sandboxes' idle time. */
function startEstimate(waiting: ThreadWaiting): { headline: string; detail: string } {
    const grace = waiting.idleGraceMinutes;
    const paused = waiting.slots.filter((slot) => slot.state === 'paused');

    if (paused.some((slot) => isStoppable(slot, grace))) {
        return { headline: 'Starts in about a minute.', detail: 'A paused sandbox is past the idle limit, and Yak checks every minute.' };
    }

    if (paused.length === 0) {
        return { headline: 'Every awake sandbox is running a turn.', detail: `This thread starts after one of those turns ends and its sandbox sits idle for ${minutes(grace)}.` };
    }

    const nearest = paused.reduce((best, slot) => ((slot.idleMinutes ?? 0) > (best.idleMinutes ?? 0) ? slot : best));
    const idle = nearest.idleMinutes ?? 0;

    return {
        headline: `Next one frees in about ${minutes(grace - idle)}.`,
        detail: `${nearest.isMine ? 'Your' : 'The'} ${idle}m sandbox reaches the limit first.`,
    };
}

function SandboxBody({ waiting }: { waiting: ThreadWaiting }) {
    const docsUrl = useDocsUrl('threads/');
    const awake = waiting.slots.length;
    const cap = Math.max(waiting.awakeCap, awake);
    const grace = waiting.idleGraceMinutes;
    const lowMemory = waiting.reason === 'memory_floor';
    const freeCount = cap - awake;
    const estimate = startEstimate(waiting);
    const hasStoppable = waiting.slots.some((slot) => isStoppable(slot, grace));
    const hasMine = waiting.slots.some((slot) => slot.isMine);

    return (
        <>
            <p className="mt-1 text-[13px] text-muted">
                {lowMemory ? (
                    <>
                        {awake} of {cap} sandboxes are awake, but the server is low on memory, so Yak frees one first. It can stop a paused sandbox once it has been idle for{' '}
                        <strong className="font-medium text-body">{minutes(grace)}</strong>. Your message is saved.
                    </>
                ) : (
                    <>
                        All {cap} sandboxes are awake. Yak can stop a paused sandbox once it has been idle for <strong className="font-medium text-body">{minutes(grace)}</strong>, and then starts this thread in its place. Your
                        message is saved.
                    </>
                )}
            </p>
            <div className="mt-3 rounded-card bg-app p-3">
                <div className="flex items-center justify-between text-[13px]">
                    <span className="text-body">Awake sandboxes</span>
                    <span className="text-muted tabular-nums">
                        {awake} of {cap}
                    </span>
                </div>
                <SlotStrip waiting={waiting} freeCount={freeCount} />
                <p className="mt-2.5 flex flex-wrap gap-x-4 gap-y-1.5 text-[12px] text-muted" data-testid="waiting-legend">
                    <span className="inline-flex items-center gap-1.5">
                        <Swatch className="bg-ok" />
                        Running a turn
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <Swatch className="border border-hair bg-panel-2" />
                        Paused, minutes idle
                    </span>
                    {hasStoppable && (
                        <span className="inline-flex items-center gap-1.5">
                            <Swatch className="bg-warn-soft outline outline-1 -outline-offset-1 outline-warn" />
                            Past {grace} minutes, can be stopped
                        </span>
                    )}
                    {freeCount > 0 && (
                        <span className="inline-flex items-center gap-1.5">
                            <Swatch className="border border-dashed border-muted" />
                            {lowMemory ? 'Free, held back for memory' : 'Free'}
                        </span>
                    )}
                    {hasMine && (
                        <span className="inline-flex items-center gap-1.5">
                            <Swatch className="ring-1 ring-inset ring-accent" />
                            Yours
                        </span>
                    )}
                </p>
                <p className="mt-2.5 flex flex-wrap items-baseline gap-x-2 border-t border-hair pt-2.5 text-[12px] text-muted" data-testid="waiting-estimate">
                    <strong className="font-medium text-body">{estimate.headline}</strong>
                    <span>{estimate.detail}</span>
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
                You already have <strong className="font-medium text-body">{waiting.ownSlotsUsed} of {waiting.ownSlotsCap}</strong> awake sandboxes, counting your threads and their sub-threads. The limit keeps one person from using up all of them, so this thread waits even though one slot may be free. It starts when one of yours goes idle for {minutes(waiting.idleGraceMinutes)}.
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
