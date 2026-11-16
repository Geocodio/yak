import { Ban, Check, UserRound } from 'lucide-react';
import type { ApprovalLevel } from '@/types/approvals';
import { LEVELS, NO_GO, levelColor } from './levels';

function verdict(n: ApprovalLevel, draftLevel: ApprovalLevel, isArmed: boolean): { text: string; approves: boolean } {
    if (n === 1) {
        return { text: draftLevel === 1 ? 'Everything waits for a human' : 'Always on: Yak reviews', approves: false };
    }

    if (n <= draftLevel) {
        return { text: isArmed ? 'Yak approves' : 'Shadow: would approve', approves: true };
    }

    return { text: 'A human reviews', approves: false };
}

export function LevelLever({ liveLevel, draftLevel, isArmed, onPick }: { liveLevel: ApprovalLevel; draftLevel: ApprovalLevel; isArmed: boolean; onPick: (level: ApprovalLevel) => void }) {
    return (
        <section aria-labelledby="approvals-lever" className="flex min-w-0 flex-1 basis-[560px] flex-col gap-4 rounded-card border border-hair bg-panel p-5">
            <div>
                <h2 id="approvals-lever" className="font-mono text-[13px] tracking-[0.18em]">THE LEVER</h2>
                <p className="mt-1 text-[14px] text-muted">Pick a level. 1 is strictest. Each level adds one kind of change to everything above it.</p>
            </div>
            <div role="radiogroup" aria-labelledby="approvals-lever" className="flex flex-col gap-2">
                {LEVELS.map((level) => {
                    const checked = level.n === draftLevel;
                    const isLive = level.n === liveLevel;
                    const result = verdict(level.n, draftLevel, isArmed);

                    return (
                        <label
                            key={level.n}
                            data-testid={`approvals-level-${level.n}`}
                            className="flex min-h-[76px] cursor-pointer flex-wrap items-center gap-x-3.5 gap-y-2.5 rounded-[12px] border-2 px-3.5 py-3 focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-accent"
                            style={checked ? { background: levelColor(level.n, 'tint'), borderColor: levelColor(level.n, 'text'), boxShadow: `0 0 22px ${levelColor(level.n, 'glow')}` } : { background: 'var(--color-panel-2)', borderColor: 'var(--color-hair)' }}
                        >
                            <input type="radio" name="approval-level" className="sr-only" checked={checked} onChange={() => onPick(level.n)} aria-label={`DEFCON ${level.n}, ${level.codename}. ${level.title}. ${result.text}.`} />
                            <span aria-hidden="true" className="flex size-5 shrink-0 items-center justify-center rounded-full border-2 bg-panel" style={{ borderColor: checked ? levelColor(level.n, 'text') : 'var(--color-hair-strong)' }}>
                                <span className="size-2.5 rounded-full" style={{ background: checked ? levelColor(level.n, 'text') : 'transparent' }} />
                            </span>
                            <span
                                aria-hidden="true"
                                className="flex size-11 shrink-0 items-center justify-center rounded-control border font-mono text-[26px] font-extrabold leading-none"
                                style={checked ? { background: levelColor(level.n, 'fill'), color: levelColor(level.n, 'ink'), borderColor: levelColor(level.n, 'fill') } : { background: levelColor(level.n, 'tint'), color: levelColor(level.n, 'text'), borderColor: levelColor(level.n, 'text') }}
                            >
                                {level.n}
                            </span>
                            <span className="flex min-w-0 flex-1 basis-[150px] flex-col gap-0.5">
                                <span className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
                                    <span className="font-mono text-[12px] font-bold tracking-[0.14em]" style={{ color: levelColor(level.n, 'text') }}>{level.codename}</span>
                                    {isLive && <span className="rounded-full bg-[var(--color-body)] px-2 py-0.5 font-mono text-[10.5px] font-bold tracking-[0.12em] text-panel">LIVE</span>}
                                    {checked && !isLive && <span className="rounded-full border border-dashed border-hair-strong px-2 py-0.5 font-mono text-[10.5px] font-bold tracking-[0.12em]">DRAFT</span>}
                                </span>
                                <span className="text-[15px] font-semibold leading-snug">{level.title}</span>
                                <span className="text-[13px] leading-snug text-muted">{level.detail}</span>
                            </span>
                            <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[13px] font-semibold ${result.approves ? 'border-hair bg-ok-soft text-ok' : 'border-hair bg-panel text-muted'}`}>
                                {result.approves ? <Check size={14} aria-hidden="true" /> : level.n > 1 ? <UserRound size={14} aria-hidden="true" /> : null}
                                {result.text}
                            </span>
                        </label>
                    );
                })}
            </div>
            <div className="flex flex-col gap-2.5 rounded-control border border-hair bg-panel-2 px-4 py-3.5">
                <div>
                    <h3 className="font-mono text-[12px] tracking-[0.16em]">NO-GO AT EVERY LEVEL</h3>
                    <span className="text-[13px] text-muted">These always go to a human, wherever the lever sits.</span>
                </div>
                <ul className="grid grid-cols-[repeat(auto-fit,minmax(230px,1fr))] gap-x-4 gap-y-2">
                    {NO_GO.map((item) => (
                        <li key={item} className="flex items-start gap-2 text-[14px] leading-snug">
                            <Ban size={15} aria-hidden="true" className="mt-0.5 shrink-0 text-fail" />
                            <span>{item}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
