import { Button, ConfirmDialog } from '@geocodio/console-ui';
import { useState } from 'react';
import type { ApprovalLevel, ApprovalMode } from '@/types/approvals';
import { LEVELS, describe, levelColor, needsConfirmation } from './levels';

type Setting = { level: ApprovalLevel; mode: ApprovalMode };

function confirmCopy(live: Setting, draft: Setting, slug: string): { title: string; body: string } {
    const level = LEVELS[draft.level - 1];
    const isArming = draft.mode === 'enforce' && !(live.level > 1 && live.mode === 'enforce');

    if (isArming) {
        return {
            title: 'Second key: confirm arming',
            body: `Effective immediately, Yak submits real approvals on ${slug} at DEFCON ${draft.level} ${level.codename}. ${level.summary} Protected assets and review findings still go to a human, and a human still merges.`,
        };
    }

    if (draft.mode === 'enforce') {
        return { title: 'Confirm: this loosens the level', body: `Effective immediately, Yak approves more on ${slug}. ${level.summary}` };
    }

    return {
        title: 'Confirm: this loosens the level',
        body: `Effective immediately, DEFCON ${draft.level} ${level.codename} applies on ${slug}, in shadow. Yak records more would-be approvals and still approves nothing.`,
    };
}

export function OrderBar({ live, draft, slug, isSaving, error, onDiscard, onTransmit }: {
    live: Setting;
    draft: Setting;
    slug: string;
    isSaving: boolean;
    error?: string;
    onDiscard: () => void;
    onTransmit: () => void;
}) {
    const [isConfirming, setIsConfirming] = useState(false);
    const isArming = draft.level > 1 && draft.mode === 'enforce' && !(live.level > 1 && live.mode === 'enforce');
    const copy = confirmCopy(live, draft, slug);

    return (
        <section
            data-testid="approvals-order"
            aria-labelledby="approvals-order-heading"
            className="sticky bottom-3 z-10 flex flex-wrap items-center justify-between gap-x-5 gap-y-3 rounded-card border border-hair-strong bg-panel px-5 py-4 shadow-lg"
        >
            <div className="min-w-0 flex-1 basis-[340px]">
                <h2 id="approvals-order-heading" className="font-mono text-[12px] tracking-[0.18em]">ORDER READY TO TRANSMIT</h2>
                <p className="mt-1 text-[15px] font-semibold leading-snug">{describe(live)} to {describe(draft)}</p>
                <p className="mt-0.5 text-[13px] leading-snug text-muted">
                    Saves this setting. It takes effect immediately, with no pull request.{' '}
                    {needsConfirmation(live, draft) ? 'Loosening or arming asks you to confirm.' : 'Tightening needs no confirmation.'}
                </p>
                {error && <p role="alert" className="mt-1 text-[13px] text-fail">{error}</p>}
            </div>
            <div className="flex flex-wrap gap-2">
                <Button onClick={onDiscard} disabled={isSaving}>Discard</Button>
                <button
                    type="button"
                    data-testid="approvals-transmit"
                    disabled={isSaving}
                    onClick={() => (needsConfirmation(live, draft) ? setIsConfirming(true) : onTransmit())}
                    className="min-h-12 cursor-pointer rounded-control border px-5 font-mono text-[13px] font-bold tracking-[0.14em] disabled:cursor-not-allowed disabled:opacity-60"
                    style={isArming
                        ? { background: 'var(--approval-armed)', color: 'var(--approval-armed-ink)', borderColor: 'var(--approval-armed)', boxShadow: '0 0 20px var(--approval-armed-glow)' }
                        : { background: levelColor(draft.level, 'fill'), color: levelColor(draft.level, 'ink'), borderColor: levelColor(draft.level, 'text'), boxShadow: `0 0 20px ${levelColor(draft.level, 'glow')}` }}
                >
                    {isArming ? 'ARM AND TRANSMIT' : 'TRANSMIT ORDER'}
                </button>
            </div>
            <ConfirmDialog
                open={isConfirming}
                onOpenChange={setIsConfirming}
                title={copy.title}
                body={copy.body}
                confirmLabel="Confirm, effective now"
                confirmTestId="approvals-confirm"
                destructive={false}
                busy={isSaving}
                onConfirm={() => {
                    setIsConfirming(false);
                    onTransmit();
                }}
            />
        </section>
    );
}
