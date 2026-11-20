import { Popover } from '@base-ui/react/popover';
import { router } from '@inertiajs/react';
import { cn } from '@geocodio/console-ui';
import { useState } from 'react';
import { store as addSteps } from '@/routes/threads/step-limit';
import { contextMeter, stepMeter, windowFor } from '@/threads/contextWindow';
import type { ThreadComposer } from '@/types/threads';

const RADIUS = 7;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

/**
 * A small ring and "62k / 200k · 142 steps" beside the model picker. Hover, focus, click or tap opens the breakdown,
 * which stays open so its "Add 300 steps" button can be pressed. The session's real usage is measured against the window
 * of the model selected now, which can differ from the model the session ran on. The context part stays hidden until a
 * value has been measured; the steps part shows only while a turn runs.
 */
export function ContextRing({ composer, model, threadId }: { composer: ThreadComposer; model: string | null; threadId: string }) {
    const meter = contextMeter(composer.context, windowFor(composer.contextWindows, model));
    const steps = stepMeter(composer.turn);

    if (meter === null && steps === null) {
        return null;
    }

    const isWarn = (meter !== null && meter.tone !== 'normal') || steps?.tone === 'near';
    const percent = meter?.percent ?? steps?.percent ?? 0;
    const label = [meter?.label, steps?.label].filter(Boolean).join(' · ');

    return (
        <Popover.Root>
            <Popover.Trigger
                openOnHover
                delay={150}
                closeDelay={200}
                className={cn('inline-flex shrink-0 cursor-default items-center gap-1.5 rounded-control text-[11px]', isWarn ? 'text-warn' : 'text-muted')}
                aria-label={[meter !== null && `Context window ${meter.percent}% used`, steps !== null && steps.label].filter(Boolean).join(', ')}
                data-testid="composer-context"
            >
                <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden>
                    <circle cx="9" cy="9" r={RADIUS} fill="none" stroke="currentColor" strokeOpacity="0.25" strokeWidth="2" />
                    <circle cx="9" cy="9" r={RADIUS} fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeDasharray={CIRCUMFERENCE} strokeDashoffset={CIRCUMFERENCE * (1 - percent / 100)} transform="rotate(-90 9 9)" />
                </svg>
                <span className="hidden @md:inline" data-testid="composer-context-label">{label}</span>
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Positioner side="top" align="start" sideOffset={8} className="z-50 outline-none">
                    <Popover.Popup className="ui-floating w-72 max-w-[calc(100vw-24px)] rounded-card border border-hair bg-panel p-3 text-[12px] text-muted shadow-overlay outline-none" data-testid="composer-context-tip">
                        {meter !== null && composer.context !== null && (
                            <div className="flex flex-col gap-1">
                                <span className="mb-0.5 block text-[12px] font-semibold text-body">Context window</span>
                                <TipRow label="Used" value={`${composer.context.tokens.toLocaleString()} of ${meter.window.toLocaleString()} (${meter.percent}%)`} />
                                <TipRow label="Read from cache" value={composer.context.cacheRead.toLocaleString()} />
                                <TipRow label="Added to cache" value={composer.context.cacheCreation.toLocaleString()} />
                                <TipRow label="New input" value={composer.context.input.toLocaleString()} />
                                <p className="mt-1">Counted at the last turn. A long thread slows down and can lose early detail. A sub-thread keeps this one short.</p>
                            </div>
                        )}
                        {meter !== null && steps !== null && <div className="my-3 h-px bg-hair" />}
                        {steps !== null && composer.turn !== null && <StepsSection steps={steps} turn={composer.turn} threadId={threadId} />}
                    </Popover.Popup>
                </Popover.Positioner>
            </Popover.Portal>
        </Popover.Root>
    );
}

function StepsSection({ steps, turn, threadId }: { steps: NonNullable<ReturnType<typeof stepMeter>>; turn: NonNullable<ThreadComposer['turn']>; threadId: string }) {
    const [isAdding, setIsAdding] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);

    const add = () => {
        setIsAdding(true);
        setFailure(null);
        router.post(addSteps.url(threadId), {}, {
            preserveScroll: true,
            preserveState: true,
            onError: () => setFailure('Could not add steps. Try again.'),
            onFinish: () => setIsAdding(false),
        });
    };

    return (
        <div className="flex flex-col gap-1" data-testid="composer-steps">
            <span className="mb-0.5 block text-[12px] font-semibold text-body">Steps this turn</span>
            <TipRow label="Taken" value={steps.limit === null ? String(steps.steps) : `${steps.steps} of ${steps.limit}`} tone={steps.tone} />
            <TipRow label="Limit" value={steps.limit === null ? 'None' : String(steps.limit)} />
            {steps.percent !== null && (
                <div className="mt-1 h-1.5 overflow-hidden rounded-pill bg-panel-2" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={steps.percent} aria-label="Steps used">
                    <div className={cn('h-full rounded-pill', steps.tone === 'near' ? 'bg-warn' : 'bg-accent')} style={{ width: `${steps.percent}%` }} />
                </div>
            )}
            <p className="mt-1">
                {steps.limit === null
                    ? 'You started this turn, so Claude keeps going until it is done. Press Stop to end it.'
                    : `Yak started this turn on its own, so it pauses at ${steps.limit} steps. Claude keeps everything it has done so far.`}
            </p>
            {turn.canAddSteps && (
                <>
                    <button
                        type="button"
                        onClick={add}
                        disabled={isAdding}
                        className="mt-1.5 inline-flex h-9 items-center justify-center rounded-control border border-hair bg-panel px-3 text-[13px] font-medium text-body hover:bg-panel-2 disabled:opacity-60"
                        data-testid="composer-add-steps"
                    >
                        {isAdding ? 'Adding...' : `Add ${turn.stepsToAdd} steps`}
                    </button>
                    {failure !== null && <p className="text-bad" role="alert" data-testid="composer-add-steps-error">{failure}</p>}
                </>
            )}
        </div>
    );
}

function TipRow({ label, value, tone = 'normal' }: { label: string; value: string; tone?: 'normal' | 'near' }) {
    return (
        <span className="flex justify-between gap-3">
            <span>{label}</span>
            <span className={cn('text-body', tone === 'near' && 'font-medium text-warn')}>{value}</span>
        </span>
    );
}
