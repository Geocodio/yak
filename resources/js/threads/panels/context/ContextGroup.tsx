import { cn } from '@geocodio/console-ui';
import type { PanelProps } from '@/threads/panels';
import { contextMeter, windowFor, type ContextTone } from '@/threads/contextWindow';
import { OverviewGroup } from '../environment/OverviewRow';

export const BAR: Record<ContextTone, string> = { normal: 'bg-muted', near: 'bg-warn', full: 'bg-warn' };

/** The Overview rail's Context row: how full the session's context window is, as of the last turn. */
export function ContextGroup({ composer, modelLabels = {} }: PanelProps) {
    const meter = composer === undefined ? null : contextMeter(composer.context, windowFor(composer.contextWindows, composer.model));

    if (meter === null) {
        return null;
    }

    return (
        <OverviewGroup title="Context" testId="overview-context">
            <div className="flex items-center justify-between text-[13px] text-body">
                <span>
                    {meter.tokens.toLocaleString()} of {meter.window.toLocaleString()} tokens
                </span>
                <span className={cn(meter.tone === 'normal' ? 'text-muted' : 'text-warn')}>{meter.percent}%</span>
            </div>
            <div className="mt-1.5 h-1.5 overflow-hidden rounded-pill bg-hair" role="progressbar" aria-valuenow={meter.percent} aria-valuemin={0} aria-valuemax={100} aria-label="Context window used">
                <div className={cn('h-full rounded-pill', BAR[meter.tone])} style={{ width: `${meter.percent}%` }} />
            </div>
            <p className="mt-1.5 text-[12px] text-muted">
                {modelLabels[composer?.model ?? ''] ?? composer?.model ?? 'The default model'}, as of the last turn
            </p>
        </OverviewGroup>
    );
}
