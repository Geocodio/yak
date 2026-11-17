import type { PanelProps } from '@/threads/panels';
import { OverviewGroup, OverviewList, OverviewRow } from './OverviewRow';

function clock(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

/** Who was asked for a held change, who is the fallback and which areas it touches; shown while a co-owner request is pending or decided. */
export function CoOwnerGroup({ coOwner }: PanelProps) {
    if (coOwner === null || coOwner === undefined) {
        return null;
    }

    const areas = coOwner.areas.map((area) => area.name);

    return (
        <OverviewGroup title="Co-owner" testId="overview-co-owner">
            <OverviewList>
                <OverviewRow label="Asked" testId="co-owner-asked">
                    {coOwner.asked.length === 0 ? <span className="text-muted">Nobody yet</span> : `${coOwner.asked.join(', ')}, ${clock(coOwner.heldAt)}`}
                </OverviewRow>
                <OverviewRow label="Fallback" testId="co-owner-fallback">
                    {coOwner.fallbackOwner ?? <span className="text-muted">None set</span>}
                </OverviewRow>
                <OverviewRow label="Areas" testId="co-owner-areas">
                    {areas.length === 0 ? <span className="text-muted">Not matched</span> : areas.join(', ')}
                </OverviewRow>
            </OverviewList>
        </OverviewGroup>
    );
}
