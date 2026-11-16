import { Badge } from '@geocodio/console-ui';
import { AlertTriangle } from 'lucide-react';
import type { AwakeSlots } from '@/types/system';
import { SlotTile } from './SlotTile';

const TONES = ['bg-accent-soft', 'bg-info-soft', 'bg-ok-soft', 'bg-warn-soft', 'bg-brand-soft'];

/** Who holds the awake sandbox slots right now. Counts only, no thread titles. */
export function AwakeSandboxesCard({ slots }: { slots: AwakeSlots }) {
    const tiles = slots.people.flatMap((person, index) => Array.from({ length: person.awake }, () => ({ initials: person.initials, tone: TONES[index % TONES.length] })));
    const free = Math.max(0, slots.total - slots.used);
    const atShare = slots.people.find((person) => person.awake >= slots.perPersonCap);

    return (
        <section className="mb-6 rounded-card border border-hair bg-app p-4" data-testid="awake-sandboxes">
            <h2 className="text-[14px] font-semibold text-body">Awake sandboxes</h2>

            <div className="mt-3 flex flex-wrap gap-1.5">
                {Array.from({ length: slots.total }, (_, index) => {
                    const tile = tiles[index];

                    return <SlotTile key={index} initials={tile?.initials ?? null} toneClass={tile?.tone} />;
                })}
            </div>

            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-muted">
                {slots.people.map((person, index) => (
                    <span key={person.name} className="inline-flex items-center gap-1.5">
                        <span className={`inline-block h-2 w-2 rounded-pill ${TONES[index % TONES.length]}`} />
                        {person.name} {person.awake}
                    </span>
                ))}
            </div>
            <p className="mt-1 text-[12.5px] text-body" data-testid="awake-sandboxes-summary">
                {slots.used} of {slots.total} slots in use, {free} free
            </p>

            {atShare !== undefined && (
                <p className="mt-3 flex items-start gap-1.5 rounded-control bg-warn-soft px-3 py-2 text-[12.5px]" data-testid="awake-sandboxes-at-share">
                    <AlertTriangle size={14} className="mt-0.5 shrink-0 text-warn" aria-hidden="true" />
                    {atShare.name} is at their share of {slots.perPersonCap}. Their next sandbox waits for one of theirs to stop.
                </p>
            )}

            <div className="mt-3 flex items-center justify-between gap-3 border-t border-hair pt-3 text-[12.5px]" data-testid="awake-sandboxes-cap">
                <div>
                    <div className="text-muted">Awake slots per person</div>
                    <div className="text-[11.5px] text-faint">Set in the server config (yak.threads.person_awake_cap).</div>
                </div>
                <span className="inline-flex items-center gap-2">
                    <span className="tnum text-body">{slots.perPersonCap} of {slots.total}</span>
                    <Badge tone="neutral">Admins only</Badge>
                </span>
            </div>
        </section>
    );
}
