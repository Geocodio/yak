import { Tooltip } from '@geocodio/console-ui';
import { HelpCircle } from 'lucide-react';

export function StatTile({ label, value, sub, hint }: { label: string; value: string; sub: string; hint?: string }) {
    return (
        <div className="rounded-card border border-hair bg-panel px-4 py-3 shadow-card">
            <div className="flex items-center gap-1 text-[11px] text-faint">
                {label}
                {hint && (
                    <Tooltip label={hint}>
                        <span className="text-faint">
                            <HelpCircle size={11} />
                        </span>
                    </Tooltip>
                )}
            </div>
            <div className="tnum mt-1 text-[20px] font-semibold tracking-tight">{value}</div>
            <div className="text-[11px] text-muted">{sub}</div>
        </div>
    );
}
