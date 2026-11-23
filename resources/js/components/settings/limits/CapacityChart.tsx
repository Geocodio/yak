import type { CapacitySuggestion } from '@/types/limits';

const WIDTH = 600;
const HEIGHT = 120;
const LEFT = 30;
const RIGHT = 590;
const TOP = 10;
const BOTTOM = 100;

const shortDate = (iso: string) => new Date(iso).toLocaleDateString('en', { month: 'short', day: 'numeric' });

/** The last 7 days: awake sandboxes against the limit, and memory used as a share of the server's memory. */
export function CapacityChart({ suggestion }: { suggestion: CapacitySuggestion }) {
    const { series, cap, from, to } = suggestion;
    const top = Math.max(cap, ...series.map((point) => point.awake), 1);
    const start = new Date(from).getTime();
    const span = Math.max(new Date(to).getTime() - start, 1);

    const x = (iso: string) => LEFT + ((new Date(iso).getTime() - start) / span) * (RIGHT - LEFT);
    const yAwake = (value: number) => BOTTOM - (value / top) * (BOTTOM - TOP);
    const yMemory = (ratio: number) => BOTTOM - ratio * (BOTTOM - TOP);
    const points = (pick: (point: CapacitySuggestion['series'][number]) => number) => series.map((point) => `${x(point.at).toFixed(1)},${pick(point).toFixed(1)}`).join(' ');
    const middle = (TOP + BOTTOM) / 2;

    return (
        <div className="flex flex-col gap-2" data-testid="capacity-chart">
            <svg viewBox={`0 0 ${WIDTH} ${HEIGHT}`} className="block h-auto w-full" role="img" aria-label={`Last 7 days: awake sandboxes against the limit of ${cap}, and memory used`}>
                {[TOP, middle, BOTTOM].map((y) => <line key={y} x1={LEFT} y1={y} x2={RIGHT} y2={y} className="stroke-hair" />)}
                <text x={LEFT - 6} y={TOP + 3} textAnchor="end" className="fill-muted text-[10px]">{top}</text>
                <text x={LEFT - 6} y={middle + 3} textAnchor="end" className="fill-muted text-[10px]">{Math.round(top / 2)}</text>
                <text x={LEFT - 6} y={BOTTOM + 3} textAnchor="end" className="fill-muted text-[10px]">0</text>
                <line x1={LEFT} y1={yAwake(cap)} x2={RIGHT} y2={yAwake(cap)} className="stroke-muted" strokeDasharray="3 3" />
                {series.length > 1 ? (
                    <>
                        <polyline fill="none" className="stroke-ok" strokeWidth="1.6" strokeLinejoin="round" points={points((point) => yMemory(point.memory))} />
                        <polyline fill="none" className="stroke-accent" strokeWidth="1.6" strokeLinejoin="round" points={points((point) => yAwake(point.awake))} />
                    </>
                ) : series.map((point) => (
                    <g key={point.at}>
                        <circle cx={x(point.at)} cy={yMemory(point.memory)} r="2.5" className="fill-ok" />
                        <circle cx={x(point.at)} cy={yAwake(point.awake)} r="2.5" className="fill-accent" />
                    </g>
                ))}
                <text x={LEFT} y={HEIGHT - 4} className="fill-muted text-[10px]">{shortDate(from)}</text>
                <text x={(LEFT + RIGHT) / 2} y={HEIGHT - 4} textAnchor="middle" className="fill-muted text-[10px]">{shortDate(new Date(start + span / 2).toISOString())}</text>
                <text x={RIGHT} y={HEIGHT - 4} textAnchor="end" className="fill-muted text-[10px]">{shortDate(to)}</text>
            </svg>
            <div className="flex flex-wrap gap-x-3.5 gap-y-1 text-[11px] text-muted">
                <LegendItem className="bg-accent" label="Awake sandboxes" />
                <LegendItem className="bg-ok" label="Memory used (0 to 100%)" />
                <LegendItem className="bg-muted" label="Limit" />
            </div>
        </div>
    );
}

function LegendItem({ className, label }: { className: string; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span className={`h-[3px] w-2.5 rounded-sm ${className}`} aria-hidden="true" />
            {label}
        </span>
    );
}
