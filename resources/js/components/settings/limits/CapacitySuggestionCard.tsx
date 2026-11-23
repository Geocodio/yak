import { router } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { useState } from 'react';
import limitsRoutes from '@/routes/settings/limits';
import type { CapacitySuggestion } from '@/types/limits';
import { CapacityChart } from './CapacityChart';

const DISMISS_KEY = 'yak.limits.capacity-suggestion-dismissed-until';
const DISMISS_DAYS = 7;

const percent = (ratio: number) => Math.round(ratio * 100);

function isDismissed(): boolean {
    try {
        return Number(window.localStorage.getItem(DISMISS_KEY) ?? 0) > Date.now();
    } catch {
        return false;
    }
}

function dismiss(): void {
    try {
        window.localStorage.setItem(DISMISS_KEY, String(Date.now() + DISMISS_DAYS * 86_400_000));
    } catch {
        // Without storage the card simply comes back on the next visit.
    }
}

const TONES: Record<CapacitySuggestion['state'], { surface: string; dot: string; title: string }> = {
    up: { surface: 'bg-ok-soft', dot: 'bg-ok', title: 'The server can take more awake sandboxes' },
    down: { surface: 'bg-fail-soft', dot: 'bg-fail', title: 'The limit may be too high for this server' },
    fits: { surface: 'bg-panel', dot: 'bg-muted', title: 'The limit fits this server' },
    wait: { surface: 'bg-panel', dot: 'bg-warn', title: 'No suggestion yet' },
};

function explanation(suggestion: CapacitySuggestion, memoryFloorGb: number): string {
    const { state, cap, suggested, samplesAtLimit, memoryPeak, cpuPeak, evictions, highestAwake, totalSamples } = suggestion;
    const highest = Math.max(memoryPeak, cpuPeak);
    const peakName = memoryPeak >= cpuPeak ? 'memory' : 'CPU';

    if (state === 'wait') {
        return totalSamples === 0
            ? 'Yak has not recorded any checks yet. It records one every 5 minutes, so a suggestion appears once the limit has been reached.'
            : `Yak did not reach the limit of ${cap} in the last 7 days, so nothing shows how the server copes with that many. The most awake at once was ${highestAwake}.`;
    }

    if (state === 'fits') {
        return `At the limit of ${cap}, the server used at most ${percent(memoryPeak)}% of its memory and ${percent(cpuPeak)}% of its CPU in the last 7 days.`;
    }

    const expected = suggested === null ? 0.7 : Math.min(0.7, (highest * suggested) / cap);
    const target = Math.max(5, Math.ceil(expected * 20) * 5);

    if (state === 'up') {
        return `In the last 7 days, Yak reached the limit of ${cap} at ${samplesAtLimit.toLocaleString('en')} checks. At those times the server never used more than ${percent(memoryPeak)}% of its memory or ${percent(cpuPeak)}% of its CPU. A limit of ${suggested} should still keep it under about ${target}%.`;
    }

    const reasons = [
        samplesAtLimit > 0 ? `${peakName} reached ${percent(highest)}% while ${cap} sandboxes were awake` : null,
        evictions > 0 ? `Yak stopped ${evictions} ${evictions === 1 ? 'sandbox' : 'sandboxes'} to keep ${memoryFloorGb} GB free` : null,
    ].filter(Boolean);

    return `In the last 7 days, ${reasons.join(', and ')}. A limit of ${suggested} should keep ${samplesAtLimit > 0 ? peakName : 'memory'} under about ${target}%.`;
}

function Meter({ label, value, ratio }: { label: string; value: string; ratio: number }) {
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <span className="text-[11px] text-muted">{label}</span>
            <span className="font-medium tabular-nums">{value}</span>
            <div className="h-[5px] overflow-hidden rounded-[3px] bg-panel-2" role="presentation">
                <i className="block h-full rounded-[3px] bg-ok" style={{ width: `${Math.min(100, Math.max(0, percent(ratio)))}%` }} />
            </div>
        </div>
    );
}

/** What the last 7 days of checks say about the awake sandbox limit, with the one-click change when they suggest one. */
export function CapacitySuggestionCard({ suggestion, memoryFloorGb }: { suggestion: CapacitySuggestion; memoryFloorGb: number }) {
    const [isHidden, setIsHidden] = useState(isDismissed);
    const [isSaving, setIsSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const { state, suggested, cap, samplesAtLimit, totalSamples } = suggestion;
    const tone = TONES[state];
    const canChange = (state === 'up' || state === 'down') && suggested !== null && suggested !== cap;

    if (isHidden && canChange) {
        return null;
    }

    const apply = () => {
        setIsSaving(true);
        setError(null);
        router.put(limitsRoutes.update.url(), { values: { awake_cap: suggested } }, {
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Yak could not save this limit.'),
            onFinish: () => setIsSaving(false),
        });
    };

    const notNow = () => {
        dismiss();
        setIsHidden(true);
    };

    return (
        <div className={`flex flex-col gap-2.5 rounded-card border border-hair p-3.5 ${tone.surface}`} data-testid="capacity-suggestion" data-state={state}>
            <div className="flex items-baseline gap-2">
                <span className={`size-2 flex-none -translate-y-px rounded-full ${tone.dot}`} aria-hidden="true" />
                <span className="font-semibold">{tone.title}</span>
            </div>
            <p>{explanation(suggestion, memoryFloorGb)}</p>

            {state !== 'wait' && totalSamples > 0 ? (
                <>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Meter label="Times at the limit" value={`${samplesAtLimit.toLocaleString('en')} of ${totalSamples.toLocaleString('en')} checks`} ratio={samplesAtLimit / totalSamples} />
                        <Meter label="Peak memory at the limit" value={`${suggestion.memoryUsedGbAtPeak} of ${suggestion.memoryTotalGb} GB · ${percent(suggestion.memoryPeak)}%`} ratio={suggestion.memoryPeak} />
                        <Meter label="Peak CPU at the limit" value={`${percent(suggestion.cpuPeak)}% of ${suggestion.cores} ${suggestion.cores === 1 ? 'core' : 'cores'}`} ratio={suggestion.cpuPeak} />
                    </div>
                    <CapacityChart suggestion={suggestion} />
                </>
            ) : null}

            {canChange ? (
                <div className="flex flex-wrap gap-1.5">
                    <Button variant="primary" pending={isSaving} onClick={apply} data-testid="capacity-apply">
                        {state === 'up' ? 'Raise' : 'Lower'} the limit to {suggested}
                    </Button>
                    <Button variant="secondary" onClick={notNow} disabled={isSaving}>Not now</Button>
                </div>
            ) : null}
            {error ? <p role="alert" className="text-[12px] text-fail">{error}</p> : null}

            <details className="text-[12px] text-muted">
                <summary className="cursor-pointer text-accent-text">How Yak works this out</summary>
                <ol className="mt-1.5 flex list-decimal flex-col gap-0.5 pl-[18px]">
                    <li>Every 5 minutes, Yak records the number of awake sandboxes next to the server&rsquo;s memory and CPU use.</li>
                    <li>It looks only at the checks from the last 7 days where the number reached the limit.</li>
                    <li>It takes the highest memory and CPU use from those checks.</li>
                    <li>If both stay under 50%, it suggests a limit that would bring the higher of the two to about 70%, at most double the current limit.</li>
                    <li>If either goes over 90%, or Yak had to stop a sandbox to keep memory free, it suggests a lower limit in the same way.</li>
                </ol>
            </details>
        </div>
    );
}

export function CapacitySuggestionSkeleton() {
    return (
        <div className="flex flex-col gap-3 rounded-card border border-hair bg-panel p-3.5" aria-busy="true" aria-label="Checking the last 7 days" data-testid="capacity-suggestion-loading">
            <div className="h-4 w-1/2 animate-pulse rounded bg-panel-2" />
            <div className="h-3 w-full animate-pulse rounded bg-panel-2" />
            <div className="h-3 w-3/4 animate-pulse rounded bg-panel-2" />
        </div>
    );
}
