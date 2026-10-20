import type { Risk } from '@/types/riskProfile';

export const LABEL: Record<Risk, string> = { critical: 'Critical', high: 'High', unknown: 'Unknown', medium: 'Medium', low: 'Low' };

/** Text colour that clears 4.5:1 on each level's fill. */
export const INK: Record<Risk, string> = { critical: '#ffffff', high: '#1b1c1f', unknown: '#ffffff', medium: '#ffffff', low: '#1b1c1f' };

export function RiskBadge({ risk }: { risk: Risk }) {
    return (
        <span
            data-testid={`risk-badge-${risk}`}
            className="inline-flex items-center rounded-control px-1.5 py-0.5 text-[11px] font-semibold leading-none"
            style={{ backgroundColor: `var(--risk-${risk})`, color: INK[risk] }}
        >
            {LABEL[risk]}
        </span>
    );
}
