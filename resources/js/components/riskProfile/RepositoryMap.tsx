import { router } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { Info } from 'lucide-react';
import { memo, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties, KeyboardEvent, ReactNode } from 'react';
import { AreaPanel } from '@/components/riskProfile/AreaPanel';
import { INK, LABEL } from '@/components/riskProfile/RiskBadge';
import { leafFor, squarify } from '@/components/riskProfile/squarify';
import type { Rect } from '@/components/riskProfile/squarify';
import type { ChangeTestFile, Risk, RiskArea, RiskMap, RiskMapNode, RiskMapSegment } from '@/types/riskProfile';

const HEADER_HEIGHT = 18;
const RISKS: Risk[] = ['critical', 'high', 'unknown', 'medium', 'low'];
const INK_DARK = '#1b1c1f';
const FLOOR_FILL = '#5d6169';
const NONE_FILL = 'repeating-linear-gradient(135deg, transparent 0 4px, var(--risk-none-hatch) 4px 5.2px)';
const UNCHECKED_FILL = 'repeating-linear-gradient(135deg, var(--risk-unknown-soft) 0 4px, var(--risk-unknown) 4px 5.5px)';

type Hover = { rect: Rect; lines: string[] };
type Kind = 'area' | 'unchecked' | 'floor' | 'none';
type PlacedSegment = { segment: RiskMapSegment; rect: Rect; isFirst: boolean };
type PlacedLeaf = { node: RiskMapNode; rect: Rect; segments: PlacedSegment[] };
type Layout = { groups: { node: RiskMapNode; rect: Rect }[]; leaves: PlacedLeaf[]; rects: Map<string, Rect> };

function inset(rect: Rect, by: number): Rect {
    return { x: rect.x + by, y: rect.y + by, width: Math.max(0, rect.width - by * 2), height: Math.max(0, rect.height - by * 2) };
}

function computeLayout(tree: RiskMapNode[], width: number, height: number): Layout {
    const layout: Layout = { groups: [], leaves: [], rects: new Map() };

    const place = (nodes: RiskMapNode[], rect: Rect): void => {
        for (const { item: node, rect: nodeRect } of squarify(nodes.map((item) => ({ value: item.files, item })), rect)) {
            layout.rects.set(node.id, nodeRect);
            if (node.children.length > 0) {
                layout.groups.push({ node, rect: nodeRect });
                place(node.children, { x: nodeRect.x + 1, y: nodeRect.y + HEADER_HEIGHT, width: nodeRect.width - 2, height: nodeRect.height - HEADER_HEIGHT - 1 });
            } else {
                const leafRect = inset(nodeRect, 1);
                const placed = squarify((node.segments ?? []).map((item) => ({ value: item.files, item })), leafRect);
                layout.leaves.push({ node, rect: leafRect, segments: placed.map(({ item, rect: segmentRect }, position) => ({ segment: item, rect: segmentRect, isFirst: position === 0 })) });
            }
        }
    };
    place(tree, { x: 0, y: 0, width, height });

    return layout;
}

function directoryOf(node: RiskMapNode): string {
    if (node.kind === 'other') {
        return `${node.paths[0] ?? ''}/ and more`;
    }

    return node.paths[0] === '' || node.paths[0] === undefined ? '(repository root)' : node.paths[0];
}

function labelOf(node: RiskMapNode): string {
    if (node.kind === 'other') {
        const count = node.pathCount ?? node.paths.length;

        return `and ${count.toLocaleString('en-US')} more ${count === 1 ? 'directory' : 'directories'}`;
    }

    return node.kind === 'files' ? 'files' : node.name;
}

function kindOf(segment: RiskMapSegment): Kind {
    if (segment.isUnchecked) {
        return 'unchecked';
    }

    return segment.area !== null ? 'area' : segment.isFloor ? 'floor' : 'none';
}

function segmentKeyOf(node: RiskMapNode, segment: RiskMapSegment): string {
    return `${node.id}:${segment.area}:${segment.isFloor}:${segment.isUnchecked}`;
}

function percentOf(files: number, total: number): string {
    const percent = total > 0 ? Math.round((files / total) * 100) : 0;

    return files > 0 && percent === 0 ? '<1%' : `${percent}%`;
}

function Swatch({ style, isDashed = false }: { style: CSSProperties; isDashed?: boolean }) {
    return <i aria-hidden="true" className={`size-3 flex-none rounded-[3px] ${isDashed ? 'border-[1.5px] border-dashed border-muted' : ''}`} style={style} />;
}

function LegendItem({ swatch, children, testId }: { swatch: ReactNode; children: ReactNode; testId?: string }) {
    return (
        <span data-testid={testId} className="inline-flex h-[26px] items-center gap-1.5 rounded-control border border-hair bg-panel pl-2 pr-2.5 text-[12px]">
            {swatch}
            {children}
        </span>
    );
}

export function MapNotice({
    reason,
    commitSha,
    onOpenAreas,
}: {
    reason: 'truncated' | 'too-large' | 'unreachable';
    commitSha: string | null;
    onOpenAreas: () => void;
}) {
    const copy = {
        truncated: `GitHub returned only part of the file list for ${commitSha ? commitSha.slice(0, 7) : 'this commit'}, so Yak cannot place every file in an area. The Areas tab and the test helper still work.`,
        'too-large': 'This repository has too many files to match against every area in time. The Areas tab and the test helper still work.',
        unreachable: 'GitHub could not be reached, so Yak cannot read the file list for this commit. Try again.',
    }[reason];

    return (
        <div data-testid="risk-map-notice" role="status" className="mx-auto flex max-w-[640px] flex-col items-center gap-3 rounded-card border border-hair bg-panel px-6 py-10 text-center">
            <div className="flex size-10 items-center justify-center rounded-full bg-panel-2">
                <Info size={18} className="text-muted" />
            </div>
            <h2 className="text-[16px] font-semibold">The map is not available</h2>
            <p className="max-w-[460px] text-[13px] text-muted">{copy}</p>
            <div className="flex gap-2">
                <Button onClick={onOpenAreas}>Open the Areas tab</Button>
                {reason === 'unreachable' && <Button onClick={() => router.reload({ only: ['riskProfile'] })}>Try again</Button>}
            </div>
        </div>
    );
}


type SegmentBlockProps = {
    leaf: RiskMapNode;
    segment: RiskMapSegment;
    rect: Rect;
    isFirst: boolean;
    area: RiskArea | undefined;
    isSelected: boolean;
    tabIndex: number;
    segmentKey: string;
    onSelectArea: (index: number) => void;
    onHover: (hover: Hover | null) => void;
    onFocusSegment: (key: string) => void;
};

const SegmentBlock = memo(function SegmentBlock({ leaf, segment, rect, isFirst, area, isSelected, tabIndex, segmentKey, onSelectArea, onHover, onFocusSegment }: SegmentBlockProps) {
    const kind = kindOf(segment);
    const directory = directoryOf(leaf);
    const fill: CSSProperties =
        kind === 'area' && area
            ? { backgroundColor: `var(--risk-${area.risk})`, color: INK[area.risk] }
            : kind === 'unchecked'
              ? { backgroundImage: UNCHECKED_FILL, boxShadow: 'inset 0 0 0 1px var(--risk-unknown)', color: 'var(--risk-unknown-ink)' }
              : kind === 'floor'
                ? { backgroundColor: FLOOR_FILL, color: '#fff' }
                : { backgroundColor: 'var(--color-panel-2)', backgroundImage: NONE_FILL, color: 'var(--color-muted)' };
    const meaning = kind === 'area' && area ? `${area.name}, ${area.risk}` : kind === 'unchecked' ? 'could not check' : kind === 'floor' ? 'always held' : 'no area';
    const files = `${segment.files.toLocaleString('en-US')} ${segment.files === 1 ? 'file' : 'files'}`;
    const style: CSSProperties = {
        left: rect.x,
        top: rect.y,
        width: rect.width,
        height: rect.height,
        ...fill,
        ...(isSelected ? { boxShadow: `inset 0 0 0 2px ${INK_DARK}, inset 0 0 0 4px #fff` } : {}),
    };
    const hasRoom = rect.width >= 64 && rect.height >= 28;
    const content = (
        <>
            {segment.isFloor && <span aria-hidden="true" data-testid="risk-map-floor-outline" className="pointer-events-none absolute inset-[3px] rounded-[2px] border-[1.5px] border-dashed border-current" />}
            {hasRoom && (
                <>
                    {isFirst && <span className="block truncate font-mono text-[10.5px] font-semibold leading-tight">{labelOf(leaf)}</span>}
                    <span className="block whitespace-nowrap text-[10px] opacity-85">
                        {kind === 'area' && area ? `${LABEL[area.risk]} · ` : ''}
                        {files}
                    </span>
                </>
            )}
        </>
    );
    const shared = {
        'data-testid': 'risk-map-segment',
        'data-kind': kind,
        'data-files': segment.files,
        'data-node': leaf.id,
        'data-floor': segment.isFloor,
        'data-segment': segmentKey,
        'aria-label': `${directory}: ${meaning}, ${files}`,
        style,
        onMouseEnter: () => onHover({ rect, lines: [directory, files, meaning] }),
        className: 'absolute overflow-hidden rounded-[3px] px-1.5 py-1 text-left',
    };

    return kind === 'area' && area ? (
        <button
            type="button"
            {...shared}
            tabIndex={tabIndex}
            aria-pressed={isSelected}
            onFocus={() => {
                onFocusSegment(segmentKey);
                onHover({ rect, lines: [directory, files, meaning] });
            }}
            onBlur={() => onHover(null)}
            onClick={() => onSelectArea(area.index)}
            className={`${shared.className} cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent`}
        >
            {content}
        </button>
    ) : (
        <div role="img" {...shared}>
            {content}
        </div>
    );
});

export function RepositoryMap({
    map,
    areas,
    pins,
    pinLabel,
    selectedArea,
    onSelectArea,
    commitSha,
    onOpenAreas,
}: {
    map: RiskMap | null;
    areas: RiskArea[];
    pins: ChangeTestFile[];
    pinLabel?: string | null;
    selectedArea: number | null;
    onSelectArea: (index: number) => void;
    commitSha: string | null;
    onOpenAreas: () => void;
}) {
    if (!map || !map.available) {
        return <MapNotice reason={map ? map.reason : 'unreachable'} commitSha={commitSha} onOpenAreas={onOpenAreas} />;
    }

    return <AvailableMap map={map} areas={areas} pins={pins} pinLabel={pinLabel ?? null} selectedArea={selectedArea} onSelectArea={onSelectArea} onOpenAreas={onOpenAreas} />;
}

function AvailableMap({
    map,
    areas,
    pins,
    pinLabel,
    selectedArea,
    onSelectArea,
    onOpenAreas,
}: {
    map: Extract<RiskMap, { available: true }>;
    areas: RiskArea[];
    pins: ChangeTestFile[];
    pinLabel: string | null;
    selectedArea: number | null;
    onSelectArea: (index: number) => void;
    onOpenAreas: () => void;
}) {
    const canvasRef = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ width: 0, height: 0 });
    const [hover, setHover] = useState<Hover | null>(null);
    const [focusKey, setFocusKey] = useState<string | null>(null);

    useLayoutEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) {
            return;
        }
        const measure = () => setSize({ width: canvas.clientWidth, height: canvas.clientHeight });
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(canvas);

        return () => observer.disconnect();
    }, []);

    const layout = useMemo(() => computeLayout(map.tree, size.width, size.height), [map.tree, size.width, size.height]);
    const areaByIndex = useMemo(() => new Map(areas.map((area) => [area.index, area])), [areas]);

    const shares = useMemo(() => {
        const byRisk = new Map<Risk, number>();
        let noArea = 0;
        let hasFloor = false;
        let hasAreaWithFloor = false;
        const visit = (nodes: RiskMapNode[]): void => {
            for (const node of nodes) {
                for (const segment of node.segments ?? []) {
                    const kind = kindOf(segment);
                    const area = segment.area === null ? undefined : areaByIndex.get(segment.area);
                    if (kind === 'area' && area) {
                        byRisk.set(area.risk, (byRisk.get(area.risk) ?? 0) + segment.files);
                        hasAreaWithFloor ||= segment.isFloor;
                    } else if (kind === 'none') {
                        noArea += segment.files;
                    }
                    hasFloor ||= segment.isFloor;
                }
                visit(node.children);
            }
        };
        visit(map.tree);

        return { byRisk, noArea, hasFloor, hasAreaWithFloor };
    }, [map.tree, areaByIndex]);

    const readingOrder = useMemo(
        () =>
            layout.leaves
                .flatMap((leaf) => leaf.segments.filter(({ rect, segment }) => rect.width >= 1 && rect.height >= 1 && kindOf(segment) === 'area').map(({ segment, rect }) => ({ key: segmentKeyOf(leaf.node, segment), area: segment.area, rect })))
                .sort((a, b) => a.rect.y - b.rect.y || a.rect.x - b.rect.x),
        [layout],
    );
    const tabStop = readingOrder.some((entry) => entry.key === focusKey) ? focusKey : (readingOrder.find((entry) => entry.area === selectedArea) ?? readingOrder[0])?.key;

    const onCanvasKeyDown = (event: KeyboardEvent<HTMLDivElement>): void => {
        const current = readingOrder.findIndex((entry) => entry.key === (event.target as HTMLElement).dataset.segment);
        if (current === -1 || readingOrder.length === 0) {
            return;
        }
        const last = readingOrder.length - 1;
        const target = { ArrowRight: Math.min(current + 1, last), ArrowDown: Math.min(current + 1, last), ArrowLeft: Math.max(current - 1, 0), ArrowUp: Math.max(current - 1, 0), Home: 0, End: last }[event.key];
        if (target === undefined) {
            return;
        }
        event.preventDefault();
        const next = readingOrder[target].key;
        setFocusKey(next);
        canvasRef.current?.querySelector<HTMLElement>(`[data-segment="${CSS.escape(next)}"]`)?.focus();
    };

    const placedPins = useMemo(() => {
        const seen = new Map<string, number>();

        return pins.flatMap((file) => {
            const node = leafFor(file.path, map.tree);
            const rect = node ? layout.rects.get(node.id) : undefined;
            if (!node || !rect) {
                return [];
            }
            const position = seen.get(node.id) ?? 0;
            seen.set(node.id, position + 1);

            return [{ file, x: Math.min(Math.max(rect.x + rect.width / 2, 7), Math.max(7, size.width - 7)), y: Math.min(Math.max(rect.y + rect.height / 2 + position * 18, 7), Math.max(7, size.height - 7)) }];
        });
    }, [pins, map.tree, layout, size.width, size.height]);

    const selected = selectedArea === null ? null : (areaByIndex.get(selectedArea) ?? null);

    return (
        <div data-testid="risk-map-view" className="rounded-card border border-hair bg-panel">
            <div className="flex items-start gap-4 border-b border-hair px-4 py-3">
                <div>
                    <h2 className="text-[15px] font-semibold">Repository map</h2>
                    <p className="mt-0.5 max-w-[560px] text-[12.5px] leading-snug text-muted">
                        Each block is a directory, sized by its file count and filled by the risk level of the area that covers it. Hover a block to read the area.
                    </p>
                </div>
                <div className="ml-auto whitespace-nowrap text-right text-[12px] leading-normal text-muted">
                    {map.totalFiles.toLocaleString('en-US')} files
                    <br />
                    {areas.length} {areas.length === 1 ? 'area' : 'areas'}
                </div>
            </div>
            <div data-testid="risk-map-legend" className="flex flex-wrap gap-1.5 border-b border-hair bg-app px-4 py-2.5">
                {RISKS.filter((risk) => (shares.byRisk.get(risk) ?? 0) > 0).map((risk) => (
                    <LegendItem key={risk} swatch={<Swatch style={{ backgroundColor: `var(--risk-${risk})` }} />}>
                        {LABEL[risk]}
                        <small className="text-[11px] text-muted">{percentOf(shares.byRisk.get(risk) ?? 0, map.totalFiles)}</small>
                    </LegendItem>
                ))}
                <LegendItem swatch={<Swatch style={{ backgroundColor: 'var(--color-panel-2)', backgroundImage: NONE_FILL }} />}>
                    No area
                    <small className="text-[11px] text-muted">{percentOf(shares.noArea, map.totalFiles)}</small>
                </LegendItem>
                {map.uncheckedFiles > 0 && (
                    <LegendItem testId="risk-map-legend-unchecked" swatch={<Swatch style={{ backgroundImage: UNCHECKED_FILL, boxShadow: 'inset 0 0 0 1px var(--risk-unknown)' }} />}>
                        Could not check
                        <small className="text-[11px] text-muted">{percentOf(map.uncheckedFiles, map.totalFiles)}</small>
                    </LegendItem>
                )}
                {shares.hasFloor && <LegendItem testId="risk-map-legend-floor" swatch={<Swatch isDashed style={{ backgroundColor: FLOOR_FILL }} />}>Always held</LegendItem>}
                {shares.hasAreaWithFloor && (
                    <LegendItem testId="risk-map-legend-area-floor" swatch={<Swatch isDashed style={{ backgroundColor: 'var(--risk-high)' }} />}>
                        Area and always held
                    </LegendItem>
                )}
                {pins.length > 0 && (
                    <LegendItem swatch={<i aria-hidden="true" className="size-2.5 flex-none rounded-full" style={{ backgroundColor: INK_DARK, boxShadow: '0 0 0 1.5px #fff' }} />}>
                        {pinLabel ?? 'Changed files'}
                    </LegendItem>
                )}
            </div>
            <div className="grid lg:grid-cols-[minmax(0,1fr)_320px]">
                <div className="min-w-0 p-3">
                    <a
                        href="#areas"
                        data-testid="risk-map-skip"
                        onClick={(event) => {
                            event.preventDefault();
                            onOpenAreas();
                        }}
                        className="sr-only rounded-control bg-panel px-2 py-1 text-[12px] focus:not-sr-only focus:mb-2 focus:inline-block focus-visible:outline-2 focus-visible:outline-accent"
                    >
                        Skip to the Areas tab
                    </a>
                    <div
                        ref={canvasRef}
                        onKeyDown={onCanvasKeyDown}
                        data-testid="risk-map-canvas"
                        onMouseLeave={() => setHover(null)}
                        className="relative h-[420px] w-full sm:h-[560px]"
                    >
                        {layout.groups.map(({ node, rect }) => (
                            <div key={node.id} data-testid="risk-map-group" className="absolute rounded-[6px] border border-hair-strong" style={{ left: rect.x, top: rect.y, width: rect.width, height: rect.height }}>
                                <span className="absolute inset-x-1.5 top-px truncate font-mono text-[10.5px] text-muted">
                                    <b className="font-semibold text-text">{node.name}/</b> {node.files.toLocaleString('en-US')}
                                </span>
                            </div>
                        ))}
                        {layout.leaves.flatMap((leaf) =>
                            leaf.segments
                                .filter(({ rect }) => rect.width >= 1 && rect.height >= 1)
                                .map(({ segment, rect, isFirst }) => {
                                    const key = segmentKeyOf(leaf.node, segment);
                                    const area = segment.area === null ? undefined : areaByIndex.get(segment.area);

                                    return (
                                        <SegmentBlock
                                            key={key}
                                            leaf={leaf.node}
                                            segment={segment}
                                            rect={rect}
                                            isFirst={isFirst}
                                            area={area}
                                            isSelected={kindOf(segment) === 'area' && segment.area === selectedArea}
                                            tabIndex={key === tabStop ? 0 : -1}
                                            segmentKey={key}
                                            onSelectArea={onSelectArea}
                                            onHover={setHover}
                                            onFocusSegment={setFocusKey}
                                        />
                                    );
                                }),
                        )}
                        {placedPins.map(({ file, x, y }, position) => {
                            const isRight = x < size.width - 170;

                            return (
                                <div
                                    key={`${file.path}:${position}`}
                                    data-testid="risk-map-pin"
                                    data-path={file.path}
                                    data-held={file.isHeld}
                                    className={`pointer-events-none absolute z-10 flex items-center gap-1 ${isRight ? '' : 'flex-row-reverse'}`}
                                    style={isRight ? { left: x - 7, top: y - 7 } : { right: size.width - x - 7, top: y - 7 }}
                                >
                                    <span
                                        className="size-3.5 flex-none rounded-full"
                                        style={{ backgroundColor: file.isHeld ? INK_DARK : '#fff', boxShadow: file.isHeld ? '0 0 0 2px #fff, 0 2px 6px #0006' : `0 0 0 2px ${INK_DARK}, 0 2px 6px #0006` }}
                                    />
                                    <span className="whitespace-nowrap rounded-[4px] px-1.5 py-0.5 font-mono text-[10px] text-white" style={{ backgroundColor: INK_DARK, boxShadow: '0 0 0 1.5px #fff' }}>
                                        {file.path.split('/').pop()}
                                    </span>
                                </div>
                            );
                        })}
                        {hover && (
                            <div
                                data-testid="risk-map-tooltip"
                                role="tooltip"
                                className="pointer-events-none absolute z-20 max-w-[240px] rounded-control bg-[#1b1c1f] px-2 py-1.5 text-[11px] leading-snug text-white shadow-lg"
                                style={{ left: Math.max(0, Math.min(hover.rect.x, size.width - 240)), top: hover.rect.y + hover.rect.height + 4 > size.height - 64 ? Math.max(0, hover.rect.y - 64) : hover.rect.y + hover.rect.height + 4 }}
                            >
                                <div className="break-words font-mono">{hover.lines[0]}</div>
                                <div className="opacity-80">{hover.lines[1]}</div>
                                <div className="break-words">{hover.lines[2]}</div>
                            </div>
                        )}
                    </div>
                </div>
                <div className="min-w-0 border-t border-hair bg-sidebar lg:border-l lg:border-t-0">
                    <AreaPanel area={selected} />
                </div>
            </div>
        </div>
    );
}

/** The riskiest area, and among equals the one covering the most files. */
export function defaultAreaIndex(areas: RiskArea[]): number | null {
    const rank: Record<Risk, number> = { critical: 4, high: 3, unknown: 2, medium: 1, low: 0 };
    const best = [...areas].sort((a, b) => rank[b.risk] - rank[a.risk] || (b.files ?? 0) - (a.files ?? 0) || a.index - b.index)[0];

    return best ? best.index : null;
}
