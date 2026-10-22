import { Deferred, router, usePage } from '@inertiajs/react';
import { Button, Combobox } from '@geocodio/console-ui';
import { Check, TriangleAlert } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { decline, tag } from '@/routes/tasks/co-owner';
import type { EarlyWarningCandidate, EarlyWarningData, EarlyWarningError } from '@/types/coOwner';

function joinNames(items: string[]): string {
    return items.length < 2 ? items.join('') : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}

function readError(value: unknown): EarlyWarningError | null {
    const candidate = Array.isArray(value) ? value[0] : value;
    return candidate !== null && typeof candidate === 'object' && 'reason' in candidate ? (candidate as EarlyWarningError) : null;
}

/** The early warning in the thread, right after the request, with the requester's co-owner picker. */
export function EarlyWarningNotice({ taskId, warning }: { taskId: number; warning: EarlyWarningData }) {
    const areas = joinNames(warning.areas.map((area) => `${area.name} (${area.risk} risk)`));

    return (
        <div className="flex items-start gap-3" data-testid="early-warning">
            <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-pill bg-panel-2 text-[11px] font-medium">Y</div>
            <div className="min-w-0 flex-1 text-[13px] leading-relaxed">
                <p>
                    <b>On it.</b> Heads up: this will likely touch <b>{areas}</b>, so it&apos;ll need a co-owner before I open a PR. Want to tag someone now?
                    Anyone with write access to {warning.repoName} can do it.
                </p>
                {warning.canPick && <Picker taskId={taskId} warning={warning} />}
                {warning.state === 'tagged' && warning.taggedName !== null && (
                    <Outcome>
                        {warning.taggedName} will be asked if the change is held. This is a guess based on the request, so it may not be needed.
                    </Outcome>
                )}
                {warning.state === 'declined' && <Outcome>Yak will ask {warning.fallbackName ?? 'a co-owner'} if the change is held.</Outcome>}
            </div>
        </div>
    );
}

function Outcome({ children }: { children: ReactNode }) {
    return (
        <p className="mt-2 flex items-start gap-1.5 text-muted" data-testid="early-warning-outcome">
            <Check className="mt-0.5 size-3.5 shrink-0 text-ok" aria-hidden />
            <span>{children}</span>
        </p>
    );
}

function Picker({ taskId, warning }: { taskId: number; warning: EarlyWarningData }) {
    const [picked, setPicked] = useState<EarlyWarningCandidate | null>(null);
    const [isSaving, setIsSaving] = useState(false);
    const error = readError((usePage().props.errors as Record<string, unknown>).earlyWarning);

    const save = (candidate: EarlyWarningCandidate) => {
        setPicked(candidate);
        setIsSaving(true);
        router.post(tag.url(taskId), { user_id: candidate.id }, { preserveScroll: true, onFinish: () => setIsSaving(false) });
    };

    return (
        <div className="mt-3 flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <Deferred
                    data="earlyWarningCandidates"
                    fallback={<span className="text-muted" data-testid="early-warning-checking">Checking who has write access to {warning.repoName}…</span>}
                >
                    <CandidateCombobox disabled={isSaving} onPick={save} />
                </Deferred>
                <Button
                    variant="tertiary"
                    disabled={isSaving}
                    onClick={() => router.post(decline.url(taskId), {}, { preserveScroll: true })}
                    data-testid="early-warning-decline"
                >
                    {warning.fallbackName === null ? 'Ask later' : `Ask ${warning.fallbackName} later`}
                </Button>
            </div>
            {error !== null && picked !== null && (
                <div role="alert" className="flex items-start gap-2 rounded-card bg-warn/10 p-3" data-testid="early-warning-error">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                    <div className="flex flex-col gap-1">
                        <b>Could not save your pick.</b>
                        <span>{picked.name} was not added, and Yak will ask the default co-owner if the change is held. Try again.</span>
                        <div>
                            <Button variant="secondary" onClick={() => save(picked)} data-testid="early-warning-retry">Try again</Button>
                        </div>
                    </div>
                </div>
            )}
            <p className="text-[12.5px] text-muted">
                Only people with write access to {warning.repoName} can be asked. If someone is missing, they need to link GitHub on their profile first.
            </p>
            <p className="text-[12px] text-faint">Showing people with write access only</p>
        </div>
    );
}

function CandidateCombobox({ disabled, onPick }: { disabled: boolean; onPick: (candidate: EarlyWarningCandidate) => void }) {
    const { earlyWarningCandidates: candidates } = usePage<{ earlyWarningCandidates: EarlyWarningCandidate[] }>().props;
    const [value, setValue] = useState<string | null>(null);

    return (
        <div className="w-64" data-testid="early-warning-picker">
            <Combobox
                options={candidates.map((candidate) => ({ value: String(candidate.id), label: `${candidate.name}, write access` }))}
                value={value}
                disabled={disabled}
                placeholder="Pick a co-owner"
                onChange={(next) => {
                    setValue(next);
                    const candidate = candidates.find((item) => String(item.id) === next);
                    if (candidate !== undefined) {
                        onPick(candidate);
                    }
                }}
            />
        </div>
    );
}
