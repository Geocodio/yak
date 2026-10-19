import { ChevronRight } from 'lucide-react';
import { RiskBadge } from '@/components/riskProfile/RiskBadge';
import { isHeldRisk } from '@/types/riskProfile';
import type { RiskArea } from '@/types/riskProfile';

function initials(owner: string): string {
    return owner.replace(/^@/, '').replace(/[^a-zA-Z0-9]/g, '').slice(0, 2).toUpperCase() || '?';
}

function Heading({ children }: { children: string }) {
    return <div className="mb-1 text-[11px] font-semibold uppercase tracking-wider text-faint">{children}</div>;
}

export function AreaPanel({ area }: { area: RiskArea | null }) {
    if (!area) {
        return (
            <div data-testid="risk-map-panel" className="p-4 text-[12.5px] text-muted">
                Select a block on the map to read the area that covers it.
            </div>
        );
    }

    return (
        <div data-testid="risk-map-panel" className="flex min-w-0 flex-col gap-3 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <RiskBadge risk={area.risk} />
                <span className="text-[12px] text-muted">{isHeldRisk(area.risk) ? 'Held for a co-owner' : 'Opens as a normal PR'}</span>
            </div>
            <h3 className="break-words text-[16px] font-semibold leading-snug tracking-tight">{area.name}</h3>
            <p className="break-words text-[12.5px] leading-normal">{area.rationale}</p>
            <div>
                <Heading>{area.files === null ? 'Paths' : `Paths · ${area.files.toLocaleString('en-US')} files`}</Heading>
                <ul className="rounded-control border border-hair bg-panel">
                    {area.paths.map(({ glob, files }) => (
                        <li key={glob} className="flex items-center gap-2 border-b border-hair px-2.5 py-1.5 font-mono text-[11px] last:border-b-0">
                            <span className="min-w-0 truncate">{glob}</span>
                            {files !== null && <em className="ml-auto whitespace-nowrap font-sans text-[10.5px] not-italic text-faint">{files.toLocaleString('en-US')}</em>}
                        </li>
                    ))}
                </ul>
            </div>
            <div>
                <Heading>Owner</Heading>
                {area.owners.length === 0 ? (
                    <div className="text-[12.5px] text-muted">No CODEOWNERS entry</div>
                ) : (
                    <ul className="flex flex-col gap-1">
                        {area.owners.map((owner) => (
                            <li key={owner} className="flex items-center gap-2 text-[12.5px]">
                                <span aria-hidden="true" className="flex size-[22px] flex-none items-center justify-center rounded-full bg-panel-2 text-[9.5px] font-medium">
                                    {initials(owner)}
                                </span>
                                <span className="min-w-0 break-words">{owner}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {area.evidence.length === 0 ? (
                <div className="text-[12px] text-faint">No evidence recorded</div>
            ) : (
                <details data-testid="risk-map-evidence" className="group text-[12px] text-muted">
                    <summary className="flex cursor-pointer list-none items-center gap-1">
                        <ChevronRight size={13} className="transition-transform group-open:rotate-90" />
                        Evidence: {area.evidence.length} {area.evidence.length === 1 ? 'item' : 'items'} from the model
                    </summary>
                    <ul className="mt-1.5 list-disc space-y-1 pl-5">
                        {area.evidence.map((item, position) => (
                            <li key={position} className="break-words">
                                {item}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}
