import type { ReactNode } from 'react';
import { LABEL, RiskBadge } from '@/components/riskProfile/RiskBadge';
import { pluralize } from '@/components/riskProfile/RiskProfileStates';
import { isHeldRisk } from '@/types/riskProfile';
import type { ChangeTestResult, Risk, RiskArea, RiskMap, RiskMapCoverageRow, RiskMapGap, RiskProfilePage } from '@/types/riskProfile';

// Mirrors RiskProfileMatcher::RISK_ORDER.
const RISK_ORDER: Risk[] = ['critical', 'high', 'unknown', 'medium', 'low'];

const format = (count: number) => count.toLocaleString('en-US');
/** Floors so a repository with any gap never reads as 100%, and shows "<1%" for a tiny non-zero share. */
function percent(part: number, whole: number): string {
    if (whole === 0 || part <= 0) {
        return '0%';
    }
    if (part >= whole) {
        return '100%';
    }
    const floored = Math.min(Math.floor((part / whole) * 100), 99);

    return floored === 0 ? '<1%' : `${floored}%`;
}

type AvailableMap = Extract<RiskMap, { available: true }>;

function Panel({ title, description, testId, children }: { title: string; description: string; testId: string; children: ReactNode }) {
    return (
        <section data-testid={testId} className="min-w-0 rounded-card border border-hair bg-panel p-4">
            <h2 className="text-[14px] font-semibold">{title}</h2>
            <p className="mb-3 mt-0.5 text-[12.5px] text-muted">{description}</p>
            {children}
        </section>
    );
}

function CoverageRow({ row, largest }: { row: RiskMapCoverageRow; largest: number }) {
    const width = (part: number) => `${(part / Math.max(row.files, 1)) * 100}%`;
    const gap = Math.max(row.files - row.coveredFiles - row.uncheckedFiles - row.floorFiles, 0);
    const parts = [
        { key: 'covered', files: row.coveredFiles, label: 'Covered', className: 'bg-accent' },
        { key: 'floor', files: row.floorFiles, label: 'Always held', className: 'bg-muted' },
        { key: 'unchecked', files: row.uncheckedFiles, label: 'Could not check', className: 'bg-warn-soft' },
        { key: 'gap', files: gap, label: 'Gap', className: 'bg-panel-2' },
    ].filter((part) => part.files > 0);
    const title = parts.map((part) => `${part.label} ${format(part.files)}`).join(', ');

    return (
        <li data-testid="risk-coverage-row" className="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-2 text-[12px]">
            <span className="truncate font-mono" title={row.name}>
                {row.name}
            </span>
            <div className="h-2.5" style={{ width: `${(row.files / Math.max(largest, 1)) * 100}%` }} title={title}>
                <div className="flex h-full overflow-hidden rounded-full bg-panel-2">
                    {parts.map((part) => (
                        <span key={part.key} data-part={part.key} className={part.className} style={{ width: width(part.files) }} />
                    ))}
                </div>
            </div>
            <span className="whitespace-nowrap text-muted">
                {percent(row.coveredFiles, row.files)} · {format(row.files)}
            </span>
        </li>
    );
}

function CoveragePanel({ map }: { map: AvailableMap }) {
    const largest = Math.max(...map.coverage.map((row) => row.files), 1);

    return (
        <Panel
            testId="risk-coverage"
            title="Coverage by directory"
            description={`${percent(map.coveredFiles, map.totalFiles)} of ${format(map.totalFiles)} files sit in an area. Bar length is file count.`}
        >
            <ul className="space-y-1.5">
                {map.coverage.map((row) => (
                    <CoverageRow key={row.name} row={row} largest={largest} />
                ))}
            </ul>
            <p className="mt-3 flex flex-wrap gap-x-3 text-[11px] text-muted">
                <span>Filled: in an area</span>
                <span>Dark grey: always held</span>
                <span>Tinted: could not check</span>
            </p>
        </Panel>
    );
}

function GapRow({ gap, largest }: { gap: RiskMapGap; largest: number }) {
    return (
        <li data-testid="risk-gap-row" data-aggregate={gap.isAggregate ? 'true' : undefined} className="text-[12px]">
            <div className="flex items-baseline gap-2">
                <span className="min-w-0 truncate font-mono" title={gap.path}>
                    {gap.path}
                </span>
                <span className="ml-auto whitespace-nowrap text-muted">{format(gap.files)} files</span>
            </div>
            <div className="mt-1 h-1.5 rounded-full bg-panel-2">
                <div className="h-full rounded-full bg-faint" style={{ width: `${(gap.files / Math.max(largest, 1)) * 100}%` }} />
            </div>
        </li>
    );
}

function GapsPanel({ map }: { map: AvailableMap }) {
    const gaps = map.gaps.slice(0, 5);
    const largest = Math.max(...gaps.map((gap) => gap.files), 1);

    return (
        <Panel testId="risk-gaps" title="Biggest gaps" description="No area covers these directories, so changes to them open as normal PRs.">
            {gaps.length === 0 ? (
                <div role="status" className="rounded-control bg-ok-soft p-3 text-[12.5px]">
                    <div className="font-medium">No gaps</div>
                    <div className="text-muted">Every directory is covered by an area.</div>
                </div>
            ) : (
                <ul className="space-y-2">
                    {gaps.map((gap) => (
                        <GapRow key={gap.path} gap={gap} largest={largest} />
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function Owners({ owners }: { owners: string[] }) {
    if (owners.length === 0) {
        return <span className="text-muted">No CODEOWNERS entry</span>;
    }

    return (
        <span className="min-w-0 break-words">
            {owners.slice(0, 2).join(', ')}
            {owners.length > 2 && <span className="text-muted"> +{owners.length - 2}</span>}
        </span>
    );
}

function ChangeChip({ children }: { children: string }) {
    return <span className="whitespace-nowrap rounded-control bg-text px-1.5 py-0.5 text-[11px] font-medium text-panel">{children}</span>;
}

function AreaCard({ area, chip, isHit }: { area: RiskArea; chip: string | null; isHit: boolean }) {
    const footprint = area.files === null ? pluralize(area.paths.length, 'path') : `${format(area.files)} files · ${pluralize(area.paths.length, 'path')}`;

    return (
        <li data-testid="risk-area-card" data-area={area.index} className={`min-w-0 rounded-card border bg-panel p-3 ${isHit ? 'border-text outline outline-1 outline-text' : 'border-hair'}`}>
            <div className="flex flex-wrap items-start gap-2">
                <h4 className="min-w-0 flex-1 break-words text-[13px] font-semibold">{area.name}</h4>
                {area.matchesNoFile && (
                    <span data-testid="risk-area-dead" className="whitespace-nowrap rounded-control bg-warn-soft px-1.5 py-0.5 text-[11px] font-medium text-text">
                        Matches no files
                    </span>
                )}
                {chip && <ChangeChip>{chip}</ChangeChip>}
            </div>
            <div className="mt-1 text-[12px] text-muted">{area.matchesNoFile ? `0 files · ${pluralize(area.paths.length, 'path')}` : footprint}</div>
            {area.matchesNoFile && (
                <ul className="mt-2 space-y-0.5">
                    {area.paths.map(({ glob }) => (
                        <li key={glob} className="flex gap-2 font-mono text-[11px]">
                            <span className="min-w-0 truncate">{glob}</span>
                            <em className="ml-auto font-sans not-italic text-muted">0</em>
                        </li>
                    ))}
                </ul>
            )}
            <div className="mt-2 text-[12px]">{area.matchesNoFile ? <span className="text-warn">Never matches</span> : <Owners owners={area.owners} />}</div>
        </li>
    );
}

function AreaRow({ area, chip }: { area: RiskArea; chip: string | null }) {
    return (
        <li data-testid="risk-area-row" data-area={area.index} className="flex flex-wrap items-center gap-2 border-b border-hair px-3 py-1.5 text-[12.5px] last:border-b-0">
            <span className="min-w-0 flex-1 break-words">{area.name}</span>
            {area.matchesNoFile && <span data-testid="risk-area-dead" className="whitespace-nowrap rounded-control bg-warn-soft px-1.5 py-0.5 text-[11px] font-medium text-text">Matches no files</span>}
            {chip && <ChangeChip>{chip}</ChangeChip>}
            <span className="text-[12px] text-muted">{area.matchesNoFile ? <span className="text-warn">Never matches</span> : <Owners owners={area.owners} />}</span>
        </li>
    );
}

function AreaGroup({ risk, areas, totalFiles, changeTest }: { risk: Risk; areas: RiskArea[]; totalFiles: number | null; changeTest: ChangeTestResult | null }) {
    const held = isHeldRisk(risk);
    const files = areas.reduce((sum, area) => sum + (area.files ?? 0), 0);
    const hitIndexes = new Set(changeTest?.verdict === 'held' ? changeTest.heldAreas.map((area) => area.index) : []);
    const touchedIndexes = new Set(changeTest ? changeTest.files.flatMap((file) => (file.area ? [file.area.index] : [])) : []);

    return (
        <section data-testid={`risk-group-${risk}`} aria-label={`${LABEL[risk]} areas`} className="space-y-2">
            <div className="flex flex-wrap items-center gap-2 text-[12px]">
                <RiskBadge risk={risk} />
                <h3 className="font-medium">{pluralize(areas.length, 'area')}</h3>
                {totalFiles !== null && (
                    <span className="text-muted">
                        {format(files)} files, {percent(files, totalFiles)} of the repository
                    </span>
                )}
                <span className="ml-auto text-muted">{held ? 'Changes are held for a co-owner' : 'Changes open as normal PRs'}</span>
            </div>
            {held ? (
                <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    {areas.map((area) => (
                        <AreaCard key={area.index} area={area} isHit={hitIndexes.has(area.index)} chip={hitIndexes.has(area.index) && changeTest ? `Holds ${changeTest.label}` : null} />
                    ))}
                </ul>
            ) : (
                <ul className="rounded-card border border-hair bg-panel">
                    {areas.map((area) => (
                        <AreaRow key={area.index} area={area} chip={touchedIndexes.has(area.index) && changeTest ? `In ${changeTest.label}` : null} />
                    ))}
                </ul>
            )}
        </section>
    );
}

export function AreasTab({ page, changeTest = null }: { page: RiskProfilePage; changeTest?: ChangeTestResult | null }) {
    const areas = page.profile?.areas ?? [];
    const map = page.map?.available ? page.map : null;

    return (
        <div className="space-y-4">
            {map && (
                <div className="grid gap-3 lg:grid-cols-2">
                    <CoveragePanel map={map} />
                    <GapsPanel map={map} />
                </div>
            )}
            <h2 className="text-[14px] font-semibold">Areas, riskiest first</h2>
            {RISK_ORDER.map((risk) => {
                const inGroup = areas.filter((area) => area.risk === risk);

                return inGroup.length === 0 ? null : <AreaGroup key={risk} risk={risk} areas={inGroup} totalFiles={map?.totalFiles ?? null} changeTest={changeTest} />;
            })}
        </div>
    );
}
