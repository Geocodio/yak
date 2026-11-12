import { ArrowUpRight } from 'lucide-react';
import { formatAgoLong } from '@/lib/format';
import type { ApprovalLevel, ApprovalShadow } from '@/types/approvals';
import { LEVELS } from './levels';

export function ShadowLog({ shadow, liveLevel }: { shadow: ApprovalShadow; liveLevel: ApprovalLevel }) {
    const isOff = liveLevel === 1;
    const stats = [
        { value: `${shadow.wouldApprove} of ${shadow.reviews}`, label: `reviews in ${shadow.days} days would be approved`, note: 'The rest go to a human, as they do today.' },
        { value: String(shadow.heldByConcern), label: 'held for touching a protected asset', note: 'These went to a human whatever the level.' },
        { value: 'Run yak:approval-report', label: 'Changed by a human afterwards', note: 'Needs GitHub, so the command checks it. Read these first.' },
    ];

    return (
        <section data-testid="approvals-shadow-log" aria-labelledby="approvals-shadow" className="flex flex-col gap-3.5 rounded-card border border-hair bg-panel p-5">
            <div>
                <h2 id="approvals-shadow" className="font-mono text-[13px] tracking-[0.18em]">SHADOW LOG</h2>
                <p className="mt-1 text-[16px] font-semibold leading-snug">
                    {isOff ? 'At DEFCON 1 Yak approves nothing, so there is nothing to replay. Pick a level to see what it would have done.' : `What Yak would have done at DEFCON ${liveLevel} ${LEVELS[liveLevel - 1].codename} on recent PRs`}
                </p>
            </div>
            {!isOff && (
                <>
                    <ul className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-2.5">
                        {stats.map((stat) => (
                            <li key={stat.label} className="flex flex-col gap-1 rounded-control border border-hair bg-panel-2 px-4 py-3.5">
                                <span className={`font-mono font-extrabold leading-tight ${stat.value.length > 8 ? 'text-[15px]' : 'text-[28px]'}`}>{stat.value}</span>
                                <span className="text-[14px] font-semibold leading-snug">{stat.label}</span>
                                <span className="text-[13px] leading-snug text-muted">{stat.note}</span>
                            </li>
                        ))}
                    </ul>
                    {shadow.recent.length > 0 && (
                        <ul className="flex flex-col divide-y divide-hair rounded-control border border-hair">
                            {shadow.recent.map((entry) => (
                                <li key={entry.url}>
                                    <a href={entry.url} target="_blank" rel="noopener noreferrer" className="flex items-center justify-between gap-3 px-3.5 py-2.5 text-[13px] hover:bg-panel-2">
                                        <span className="font-medium">{entry.title ?? `PR #${entry.number}`}</span>
                                        <span className="flex items-center gap-1.5 text-muted">
                                            {formatAgoLong(entry.createdAt)}
                                            <ArrowUpRight size={13} aria-hidden="true" />
                                        </span>
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </section>
    );
}
