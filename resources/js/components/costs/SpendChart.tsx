import { useState } from 'react';
import type { ChartBucket } from '@/types/costs';

export function SpendChart({ buckets, max }: { buckets: ChartBucket[]; max: number }) {
    const W = 800;
    const H = 220;
    const L = 36;
    const B = 24;
    const bw = buckets.length > 0 ? (W - L) / buckets.length : W - L;
    const [hover, setHover] = useState<number | null>(null);

    if (buckets.length === 0) {
        return <p className="py-12 text-center text-[13px] text-muted">No cost data for this period.</p>;
    }

    const yTicks = [0, max / 4, max / 2, (max * 3) / 4, max];
    const labelEvery = Math.max(1, Math.floor(buckets.length / 6));

    return (
        <div className="relative">
            <svg viewBox={`0 0 ${W} ${H}`} className="w-full" onMouseLeave={() => setHover(null)}>
                {yTicks.map((v) => {
                    const y = H - B - (v / max) * (H - B - 10);
                    return (
                        <g key={v}>
                            <line x1={L} x2={W} y1={y} y2={y} stroke="var(--border)" />
                            <text x={L - 6} y={y + 3} fontSize="10" textAnchor="end" fill="var(--text-3)">
                                ${v.toFixed(0)}
                            </text>
                        </g>
                    );
                })}
                {buckets.map((bucket, i) => {
                    const h = max > 0 ? (bucket.claudeCode / max) * (H - B - 10) : 0;
                    const apiH = max > 0 ? (bucket.api / max) * (H - B - 10) : 0;
                    const x = L + i * bw + 3;
                    const active = bucket.current || hover === i;
                    return (
                        <g key={i} onMouseEnter={() => setHover(i)}>
                            <rect x={x - 3} y={0} width={bw} height={H - B} fill="transparent" />
                            <rect
                                x={x}
                                y={H - B - h}
                                width={bw - 6}
                                height={h}
                                rx="3"
                                fill={active ? 'var(--accent)' : 'var(--accent-soft)'}
                                stroke={active ? 'none' : 'var(--accent)'}
                                strokeOpacity="0.5"
                            />
                            <rect x={x} y={H - B - apiH} width={bw - 6} height={apiH} rx="2" fill="var(--info)" />
                            {i % labelEvery === 0 || i === buckets.length - 1 ? (
                                <text x={x + (bw - 6) / 2} y={H - 6} fontSize="10" textAnchor="middle" fill="var(--text-3)">
                                    {bucket.label}
                                </text>
                            ) : null}
                        </g>
                    );
                })}
            </svg>
            {hover !== null && (
                <div
                    className="pointer-events-none absolute -top-2 rounded-card border border-hair bg-panel px-2.5 py-1.5 text-[11px] shadow-overlay"
                    style={{ left: `${((L + hover * bw) / W) * 100}%` }}
                >
                    <div className="font-medium">{buckets[hover].label}</div>
                    <div className="tnum text-muted">
                        Claude Code ${buckets[hover].claudeCode.toFixed(2)} · API ${buckets[hover].api.toFixed(2)}
                    </div>
                </div>
            )}
        </div>
    );
}
