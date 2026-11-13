import { cn } from '@geocodio/console-ui';
import { CircleCheck, CircleAlert } from 'lucide-react';
import { useEffect, useMemo, useRef } from 'react';
import { groupActivity, groupDuration, type ActivityStep } from '@/components/tasks/groupActivity';
import type { ActivityRow } from '@/types/tasks';
import { PendingStep } from './CurrentStep';
import { OpenKeysProvider } from './openKeys';
import { ActiveGroup, LoadEarlier, StepGroup, type EarlierSteps } from './StepGroup';
import type { StepCategory, StepView } from './types';

export function stepFromRow(step: ActivityStep): StepView {
    return {
        key: `s${step.id}`,
        logId: step.id,
        category: step.category as StepCategory,
        tool: step.tool,
        label: step.label,
        durationMs: step.durationMs,
        exitCode: step.exitCode,
        failed: step.error,
        running: step.running,
        retryPassed: step.retryPassed,
        retried: step.retried,
        at: step.at,
        createdAt: step.createdAt,
    };
}

function MilestoneLine({ row, fresh }: { row: ActivityRow; fresh: boolean }) {
    const problem = row.badge === 'error' || row.badge === 'warning';
    const Icon = problem ? CircleAlert : CircleCheck;

    return (
        <p className={cn('flex min-w-0 items-center gap-1.5 px-0.5 text-[12px] text-muted', fresh && 'activity-rise')} data-testid="stream-milestone">
            <Icon size={13} className={cn('shrink-0', problem ? 'text-fail' : 'text-ok')} aria-hidden />
            <span className="min-w-0 truncate">{row.text}</span>
        </p>
    );
}

/**
 * Yak's activity as a stream: the agent's sentences, each followed by the
 * steps it took as one group. While the run is live the last group is the
 * active one; otherwise every group is folded to a line. Rows that arrive
 * after the first paint rise in.
 */
export function ActivityStream({
    rows,
    live,
    startedAt,
    earlierCount,
    hasOlder,
    loadingOlder,
    onLoadOlder,
}: {
    rows: ActivityRow[];
    live: boolean;
    /** When the run began (ISO 8601); times the placeholder shown before its first step. */
    startedAt: string | null;
    /** Entries in the run beyond the loaded rows. */
    earlierCount: number;
    hasOlder: boolean;
    loadingOlder: boolean;
    onLoadOlder: () => void;
}) {
    const nodes = useMemo(() => groupActivity(rows), [rows]);
    const seenKeys = useRef(new Set<string>());
    const hasPainted = useRef(false);

    const isFresh = (key: string) => hasPainted.current && !seenKeys.current.has(key);
    const freshKeys = new Set<string>();
    nodes.forEach((node) => {
        if (node.type === 'group') {
            node.steps.forEach((step) => isFresh(`s${step.id}`) && freshKeys.add(`s${step.id}`));
        }
    });

    useEffect(() => {
        nodes.forEach((node) => {
            if (node.type === 'group') {
                node.steps.forEach((step) => seenKeys.current.add(`s${step.id}`));
            } else {
                seenKeys.current.add(node.key);
            }
        });
        hasPainted.current = true;
    }, [nodes]);

    const earlier: EarlierSteps | undefined = hasOlder ? { count: Math.max(1, earlierCount), loading: loadingOlder, onLoad: onLoadOlder } : undefined;
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    const lastRow = rows[rows.length - 1];

    return (
        <OpenKeysProvider>
            <div className="flex flex-col gap-2" data-testid="activity-stream">
                {earlier && first?.type !== 'group' && <LoadEarlier earlier={earlier} />}
                {nodes.map((node, index) => {
                    if (node.type === 'narrative') {
                        return (
                            <p key={node.key} className={cn('max-w-[65ch] px-0.5 py-0.5 text-[14px] leading-[1.55] whitespace-pre-line', isFresh(node.key) && 'activity-rise')} data-testid="stream-narrative">
                                {node.row.text}
                            </p>
                        );
                    }

                    if (node.type === 'milestone') {
                        return <MilestoneLine key={node.key} row={node.row} fresh={isFresh(node.key)} />;
                    }

                    const steps = node.steps.map(stepFromRow);
                    const groupEarlier = index === 0 ? earlier : undefined;

                    return live && node === last ? (
                        <ActiveGroup key={node.key} groupKey={node.key} steps={steps} earlier={groupEarlier} freshKeys={freshKeys} currentSince={lastRow?.createdAt ?? startedAt} />
                    ) : (
                        <StepGroup key={node.key} groupKey={node.key} steps={steps} duration={groupDuration(node.steps)} earlier={groupEarlier} freshKeys={freshKeys} />
                    );
                })}
                {live && last?.type !== 'group' && <PendingStep since={lastRow?.createdAt ?? startedAt} />}
            </div>
        </OpenKeysProvider>
    );
}
