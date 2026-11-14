import { cn } from '@geocodio/console-ui';
import { contextMeter, windowFor } from '@/threads/contextWindow';
import type { ThreadComposer } from '@/types/threads';

const RADIUS = 7;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

/**
 * A small ring and "62k / 200k" beside the model picker, with the breakdown on hover or focus.
 * The session's real usage is measured against the window of the model selected now, which can
 * differ from the model the session ran on. Hidden until a value has been measured.
 */
export function ContextRing({ composer, model }: { composer: ThreadComposer; model: string | null }) {
    const meter = contextMeter(composer.context, windowFor(composer.contextWindows, model));

    if (meter === null || composer.context === null) {
        return null;
    }

    const { context } = composer;
    const color = meter.tone === 'normal' ? 'text-muted' : 'text-warn';

    return (
        <span className="group relative inline-flex shrink-0" data-testid="composer-context">
            <span className={cn('inline-flex items-center gap-1.5 text-[11px]', color)} tabIndex={0} aria-label={`Context window ${meter.percent}% used`}>
                <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden>
                    <circle cx="9" cy="9" r={RADIUS} fill="none" stroke="currentColor" strokeOpacity="0.25" strokeWidth="2" />
                    <circle cx="9" cy="9" r={RADIUS} fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeDasharray={CIRCUMFERENCE} strokeDashoffset={CIRCUMFERENCE * (1 - meter.percent / 100)} transform="rotate(-90 9 9)" />
                </svg>
                <span data-testid="composer-context-label">{meter.label}</span>
            </span>
            <span role="tooltip" className="pointer-events-none absolute bottom-full left-0 z-20 mb-2 hidden w-64 rounded-card border border-hair bg-panel p-3 text-[12px] text-muted shadow-card group-focus-within:block group-hover:block" data-testid="composer-context-tip">
                <span className="mb-1.5 block text-[12px] font-semibold text-body">Context window</span>
                <TipRow label="Used" value={`${context.tokens.toLocaleString()} of ${meter.window.toLocaleString()} (${meter.percent}%)`} />
                <TipRow label="Read from cache" value={context.cacheRead.toLocaleString()} />
                <TipRow label="Added to cache" value={context.cacheCreation.toLocaleString()} />
                <TipRow label="New input" value={context.input.toLocaleString()} />
                <span className="mt-2 block">Counted at the last turn. A long thread slows down and can lose early detail. A sub-thread keeps this one short.</span>
            </span>
        </span>
    );
}

function TipRow({ label, value }: { label: string; value: string }) {
    return (
        <span className="flex justify-between gap-3">
            <span>{label}</span>
            <span className="text-body">{value}</span>
        </span>
    );
}
