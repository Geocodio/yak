import { Eye, UserRound, Zap } from 'lucide-react';
import { formatAgoLong } from '@/lib/format';
import type { ApprovalSetting, ApprovalMode, ApprovalLevel } from '@/types/approvals';
import { LEVELS, describe, levelColor } from './levels';

type Setting = { level: ApprovalLevel; mode: ApprovalMode };

function lastChanged(approval: ApprovalSetting): string {
    if (approval.changedAt === null) {
        return 'Never changed';
    }

    const ago = formatAgoLong(approval.changedAt);

    return approval.changedBy ? `Last changed by ${approval.changedBy}, ${ago}` : `Last changed from the command line, ${ago}`;
}

export function LevelHero({ approval, draft, isDirty }: { approval: ApprovalSetting; draft: Setting; isDirty: boolean }) {
    const shown = LEVELS[draft.level - 1];
    const isOff = draft.level === 1;
    const isArmed = !isOff && draft.mode === 'enforce';
    const modeWord = isOff ? 'REVIEW ONLY' : isArmed ? 'ARMED' : 'SHADOW';
    const modeText = isOff ? 'Every PR waits for a human.' : isArmed ? 'Yak submits real approvals.' : 'Yak records what it would approve, without approving.';
    const ModeIcon = isOff ? UserRound : isArmed ? Zap : Eye;

    return (
        <section data-testid="approvals-hero" aria-labelledby="approvals-status" className="rounded-card border border-hair p-6" style={{ backgroundColor: 'var(--color-panel)', backgroundImage: `linear-gradient(120deg, ${levelColor(draft.level, 'tint')} 0%, transparent 70%)` }}>
            <div className="flex flex-wrap items-center gap-6">
                <div
                    aria-hidden="true"
                    className="flex size-[168px] shrink-0 flex-col items-center justify-center gap-2 rounded-[18px] font-mono"
                    style={{ background: levelColor(draft.level, 'fill'), color: levelColor(draft.level, 'ink'), boxShadow: `0 0 44px ${levelColor(draft.level, 'glow')}, 0 0 0 4px var(--color-panel), 0 0 0 5px ${levelColor(draft.level, 'text')}` }}
                >
                    <span className="pl-[0.3em] text-[13px] font-bold tracking-[0.3em]">DEFCON</span>
                    <span className="text-[92px] font-extrabold leading-[0.9]">{draft.level}</span>
                </div>
                <div className="flex min-w-0 flex-1 basis-[300px] flex-col items-start gap-2.5">
                    <span className={`inline-flex items-center gap-2 rounded-full border px-2.5 py-1 font-mono text-[11.5px] font-bold tracking-[0.14em] ${isDirty ? 'border-dashed border-hair-strong bg-panel' : 'border-hair bg-ok-soft text-ok'}`}>
                        <span aria-hidden="true" className="size-2 rounded-full bg-current" />
                        {isDirty ? 'DRAFT ORDER, NOT SAVED' : 'LIVE NOW'}
                    </span>
                    <h2 id="approvals-status" className="font-mono text-[30px] font-extrabold leading-[1.1] tracking-[0.06em]" style={{ color: levelColor(draft.level, 'text') }}>
                        <span className="sr-only">DEFCON {draft.level}, </span>
                        {shown.codename}
                    </h2>
                    <p className="text-[18px] leading-snug">{shown.summary}</p>
                    <span className={`inline-flex items-center gap-2 rounded-control border px-3 py-1.5 text-[13px] ${isArmed ? 'border-fail bg-fail-soft text-fail' : 'border-hair bg-panel'}`}>
                        <ModeIcon size={15} aria-hidden="true" />
                        <span>
                            <strong className="font-mono font-bold tracking-[0.12em]">{modeWord}</strong> {modeText}
                        </span>
                    </span>
                </div>
            </div>
            <div className="mt-5 flex flex-wrap items-stretch gap-2.5">
                <div className="flex min-w-[260px] flex-1 flex-col gap-1 rounded-control border border-hair bg-panel px-3.5 py-3">
                    <span className="font-mono text-[11px] tracking-[0.16em] text-muted">CURRENT SETTING</span>
                    <span className="text-[14.5px] font-semibold">{describe(approval)}</span>
                    <span className="text-[13px] text-muted">{lastChanged(approval)}</span>
                </div>
                {isDirty && (
                    <div className="flex min-w-[260px] flex-1 flex-col gap-1 rounded-control border border-dashed px-3.5 py-3" style={{ background: levelColor(draft.level, 'tint'), borderColor: levelColor(draft.level, 'text') }}>
                        <span className="font-mono text-[11px] tracking-[0.16em] text-muted">DRAFT ORDER</span>
                        <span className="text-[14.5px] font-semibold">{describe(draft)}</span>
                        <span className="text-[13px] text-muted">Not saved yet. Nothing has changed.</span>
                    </div>
                )}
            </div>
        </section>
    );
}
