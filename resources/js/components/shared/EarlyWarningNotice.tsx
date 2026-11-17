import { router } from '@inertiajs/react';
import { Button, Combobox } from '@geocodio/console-ui';
import { Check, TriangleAlert } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import type { EarlyWarningCandidate, EarlyWarningData, EarlyWarningError } from '@/types/coOwner';

function joinNames(items: string[]): string {
    return items.length < 2 ? items.join('') : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}

function readError(value: unknown): EarlyWarningError | null {
    const candidate = Array.isArray(value) ? value[0] : value;
    return candidate !== null && typeof candidate === 'object' && 'reason' in candidate ? (candidate as EarlyWarningError) : null;
}

export type EarlyWarningRoutes = { tag: string; decline: string };

/** What the picker renders for the people the requester can tag: a combobox, or its loading and error states. */
export type RenderCandidates = (props: { disabled: boolean; onPick: (candidate: EarlyWarningCandidate) => void }) => ReactNode;

type NoticeProps = {
    warning: EarlyWarningData;
    routes: EarlyWarningRoutes;
    renderCandidates: RenderCandidates;
    /** `card` is the card above the thread composer; `notice` is the bubble the task page shows after the request. */
    variant?: 'notice' | 'card';
};

/** The early warning, with the requester's co-owner picker. */
export function EarlyWarningNotice({ warning, routes, renderCandidates, variant = 'notice' }: NoticeProps) {
    const areas = joinNames(warning.areas.map((area) => `${area.name} (${area.risk} risk)`));
    const isCard = variant === 'card';
    const content = (
        <>
            <p>
                {!isCard && <b>On it. </b>}Heads up: this will likely touch <b>{areas}</b>, so it&apos;ll need a co-owner before I open a PR. Want to tag someone now?
                Anyone with write access to {warning.repoName} can do it.
            </p>
            {warning.canPick && <Picker warning={warning} routes={routes} renderCandidates={renderCandidates} />}
            {warning.state === 'tagged' && warning.taggedName !== null && (
                <Outcome>
                    {warning.taggedName} will be asked if the change is held. This is a guess based on the request, so it may not be needed.
                </Outcome>
            )}
            {warning.state === 'declined' && <Outcome>Yak will ask {warning.fallbackName ?? 'a co-owner'} if the change is held.</Outcome>}
        </>
    );

    if (isCard) {
        return (
            <section className="rounded-card bg-warn-soft px-4 py-4 text-[13px] leading-relaxed" aria-labelledby="early-warning-title" data-testid="card-early-warning">
                <h2 id="early-warning-title" className="mb-1 text-[15px] font-semibold">This change may need a co-owner</h2>
                {content}
            </section>
        );
    }

    return (
        <div className="flex items-start gap-3" data-testid="early-warning">
            <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-pill bg-panel-2 text-[11px] font-medium">Y</div>
            <div className="min-w-0 flex-1 text-[13px] leading-relaxed">{content}</div>
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

function Picker({ warning, routes, renderCandidates }: { warning: EarlyWarningData; routes: EarlyWarningRoutes; renderCandidates: RenderCandidates }) {
    const [picked, setPicked] = useState<EarlyWarningCandidate | null>(null);
    const [lastAction, setLastAction] = useState<'pick' | 'decline' | null>(null);
    const [isSaving, setIsSaving] = useState(false);
    const [error, setError] = useState<EarlyWarningError | null>(null);

    const send = (action: 'pick' | 'decline', url: string, data: Record<string, number>) => {
        setLastAction(action);
        setIsSaving(true);
        router.post(url, data, {
            preserveScroll: true,
            onStart: () => setError(null),
            onError: (errors) => setError(readError(errors.earlyWarning)),
            onFinish: () => setIsSaving(false),
        });
    };
    const save = (candidate: EarlyWarningCandidate) => {
        setPicked(candidate);
        send('pick', routes.tag, { user_id: candidate.id });
    };
    const skip = () => send('decline', routes.decline, {});

    return (
        <div className="mt-3 flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">
                {renderCandidates({ disabled: isSaving, onPick: save })}
                <Button variant="tertiary" pending={isSaving && lastAction === 'decline'} disabled={isSaving} onClick={skip} data-testid="early-warning-decline">
                    {warning.fallbackName === null ? 'Ask later' : `Ask ${warning.fallbackName} later`}
                </Button>
            </div>
            {error !== null && !isSaving && lastAction !== null && (
                <div role="alert" className="flex items-start gap-2 rounded-card bg-warn/10 p-3" data-testid="early-warning-error">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                    <div className="flex flex-col gap-1">
                        {lastAction === 'pick' && picked !== null ? (
                            <>
                                <b>Could not save your pick.</b>
                                <span>{picked.name} was not added, and Yak will ask the default co-owner if the change is held. Try again.</span>
                            </>
                        ) : (
                            <>
                                <b>Could not save your answer.</b>
                                <span>{error.message}</span>
                            </>
                        )}
                        <div>
                            <Button variant="secondary" onClick={() => (lastAction === 'pick' && picked !== null ? save(picked) : skip())} data-testid="early-warning-retry">Try again</Button>
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

/** The people with write access, as a combobox. */
export function CandidateCombobox({ candidates, disabled, onPick }: { candidates: EarlyWarningCandidate[]; disabled: boolean; onPick: (candidate: EarlyWarningCandidate) => void }) {
    const [value, setValue] = useState<string | null>(null);

    return (
        <div className="w-64" data-testid="early-warning-picker">
            <Combobox
                options={candidates.map((candidate) => ({ value: String(candidate.id), label: `${candidate.name}, write access` }))}
                value={value}
                disabled={disabled}
                aria-label="Co-owner"
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
