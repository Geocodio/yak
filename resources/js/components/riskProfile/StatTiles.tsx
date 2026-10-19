import type { ReactNode } from 'react';
import type { Risk, RiskMap, RiskProfileDetail } from '@/types/riskProfile';

const ORDER: Risk[] = ['critical', 'high', 'unknown', 'medium', 'low'];

function Tile({ testId, title, children }: { testId: string; title: string; children: ReactNode }) {
    return (
        <div data-testid={testId} className="flex min-w-0 flex-col rounded-card border border-hair bg-panel px-3.5 py-3">
            <h4 className="text-[12px] font-medium text-muted">{title}</h4>
            {children}
        </div>
    );
}

function Big({ value, unit }: { value: string; unit?: string }) {
    return (
        <div className="mt-0.5 text-[24px] font-semibold leading-tight tracking-tight">
            {value}
            {unit && <>{' '}<small className="text-[13px] font-normal tracking-normal text-muted">{unit}</small></>}
        </div>
    );
}

export function StatTiles({ profile, map }: { profile: RiskProfileDetail; map: RiskMap | null }) {
    const present = ORDER.filter((risk) => profile.riskCounts[risk] > 0);
    const coverage = map?.available ? map : null;
    const total = coverage?.totalFiles ?? 0;
    const uncovered = coverage ? total - coverage.coveredFiles : 0;
    const percent = coverage && total > 0 ? Math.floor((coverage.coveredFiles / total) * 100) : 0;

    return (
        <div data-testid="risk-profile-tiles" className="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-[1.45fr_1fr_1fr]">
            <Tile testId="risk-tile-areas" title="Areas">
                <Big value={String(profile.areas.length)} unit="in the profile" />
                <div className="mt-2 flex h-2 gap-0.5">
                    {present.map((risk) => (
                        <span key={risk} title={`${risk}: ${profile.riskCounts[risk]}`} className="min-w-[3px] rounded-[2px]" style={{ flex: profile.riskCounts[risk], backgroundColor: `var(--risk-${risk})` }} />
                    ))}
                </div>
                <div className="mt-2 flex flex-wrap gap-x-3 gap-y-0.5 text-[11.5px] text-muted">
                    {present.map((risk) => (
                        <span key={risk} className="inline-flex items-center gap-1.5">
                            <i className="size-[7px] rounded-full" style={{ backgroundColor: `var(--risk-${risk})` }} />
                            <b className="font-semibold text-text">{profile.riskCounts[risk]}</b>
                            {risk}
                        </span>
                    ))}
                </div>
            </Tile>
            <Tile testId="risk-tile-coverage" title="Coverage">
                {coverage ? (
                    <>
                        <Big value={`${percent}%`} unit="of files" />
                        <div className="mt-2 flex h-2 gap-0.5">
                            <span className="rounded-[2px] bg-muted" style={{ flex: coverage.coveredFiles }} />
                            <span className="rounded-[2px] border border-hair bg-panel-2" style={{ flex: uncovered }} />
                        </div>
                        <div className="mt-1.5 text-[11.5px] text-muted">
                            {coverage.coveredFiles.toLocaleString('en-US')} in an area, {uncovered.toLocaleString('en-US')} in none
                        </div>
                    </>
                ) : (
                    <>
                        <Big value="–" />
                        <div className="mt-2 text-[11.5px] text-muted">Needs the full file list</div>
                    </>
                )}
            </Tile>
            <Tile testId="risk-tile-always-held" title="Always held">
                <Big value={String(profile.alwaysHeld.length)} unit={profile.alwaysHeld.length === 1 ? 'path' : 'paths'} />
                <div className="mt-1.5 break-words font-mono text-[10.5px] leading-relaxed text-muted">{profile.alwaysHeld.join(' · ')}</div>
            </Tile>
        </div>
    );
}
