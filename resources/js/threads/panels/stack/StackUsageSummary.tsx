import type { StackData } from '@/types/stack';

export function StackUsageSummary({ usage }: { usage: StackData['usage'] }) {
    return (
        <div data-testid="stack-usage">
            <p className="text-[13px] text-body">
                {usage.turnCount} {usage.turnCount === 1 ? 'turn' : 'turns'} <span className="text-muted">·</span> ${usage.costUsd.toFixed(2)} <span className="text-muted">·</span> {usage.activeHours.toFixed(1)} h
            </p>
            <p className="mt-1 text-[12px] text-muted">{usage.note}</p>
        </div>
    );
}
