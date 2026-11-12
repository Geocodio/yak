import { Badge, Button } from '@geocodio/console-ui';
import { ArrowDown, ArrowUp, ChevronRight, ExternalLink, GitBranch } from 'lucide-react';
import { useState } from 'react';
import type { PullRequestRow } from '@/types/thread-pull-requests';
import { checksLabel, durationLabel } from './pullRequestsState';
import { SiblingBlockPreview } from './SiblingBlockPreview';

const STATE_LABEL = { open: 'Open', merged: 'Merged', closed: 'Closed' } as const;
const CI_LABEL: Record<string, string> = { passing: 'CI passing', failing: 'CI failing', running: 'CI running' };
const CI_TONE: Record<string, 'ok' | 'fail' | 'info'> = { passing: 'ok', failing: 'fail', running: 'info' };

type Props = {
    row: PullRequestRow;
    position: number;
    isFirst: boolean;
    isLast: boolean;
    canDrive: boolean;
    isBusy: boolean;
    onMove: (step: -1 | 1) => void;
    onAskToFix: () => void;
};

export function PullRequestCard({ row, position, isFirst, isLast, canDrive, isBusy, onMove, onAskToFix }: Props) {
    const [isExpanded, setIsExpanded] = useState(row.ci?.status === 'failing');
    const isEnded = row.state !== 'open';
    const summary = row.ci === null ? null : checksLabel(row.ci.passedChecks, row.ci.totalChecks);
    const hasChecks = (row.ci?.checks.length ?? 0) > 0;
    const testKey = row.repo;

    return (
        <li className="relative flex gap-2.5" data-testid={`pull-request-card-${testKey}`} data-state={row.state}>
            {!isLast && <span aria-hidden className="absolute -bottom-3 left-2.5 top-[1.75rem] w-px -translate-x-1/2 bg-hair" data-testid="merge-connector" />}
            <span className="relative z-10 mt-2 flex size-5 shrink-0 items-center justify-center rounded-pill border border-hair bg-panel text-[11px] text-muted">{position}</span>
            <div className={`min-w-0 flex-1 overflow-hidden rounded-card border border-hair bg-panel ${isEnded ? 'opacity-60' : ''}`}>
                <div className="flex items-start gap-2 px-3 py-2.5">
                    <span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-chip bg-accent-soft text-[10px] font-semibold text-accent-text">{row.repoInitials}</span>
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="font-mono text-[12.5px] font-medium text-body">{row.repo}</span>
                            <a href={row.url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-0.5 font-mono text-[12px] text-accent-text hover:underline">
                                {row.number !== null ? `#${row.number}` : 'PR'}
                                <ExternalLink size={10} aria-hidden />
                            </a>
                            <Badge tone={row.state === 'merged' ? 'accent' : row.state === 'open' ? 'ok' : 'neutral'}>{STATE_LABEL[row.state]}</Badge>
                        </div>
                        <p className="mt-0.5 text-[13px] text-body">{row.title}</p>
                    </div>
                    {canDrive && !isEnded && (
                        <div className="flex shrink-0 gap-1">
                            <button type="button" aria-label={`Move ${row.repo} earlier`} disabled={isFirst || isBusy} onClick={() => onMove(-1)} data-testid={`merge-order-up-${testKey}`} className="flex size-6 cursor-pointer items-center justify-center rounded-chip border border-hair text-body disabled:cursor-default disabled:opacity-40">
                                <ArrowUp size={12} aria-hidden />
                            </button>
                            <button type="button" aria-label={`Move ${row.repo} later`} disabled={isLast || isBusy} onClick={() => onMove(1)} data-testid={`merge-order-down-${testKey}`} className="flex size-6 cursor-pointer items-center justify-center rounded-chip border border-hair text-body disabled:cursor-default disabled:opacity-40">
                                <ArrowDown size={12} aria-hidden />
                            </button>
                        </div>
                    )}
                </div>

                {isEnded && row.closedAt && <p className="px-3 pb-2 text-[12px] text-muted">{STATE_LABEL[row.state]} {new Date(row.closedAt).toLocaleDateString()}</p>}

                {row.mergeAfter && (
                    <p className="flex items-start gap-1.5 bg-panel-2 px-3 py-2 text-[12.5px] text-muted" data-testid={`merge-after-${testKey}`}>
                        <GitBranch size={13} aria-hidden className="mt-0.5 shrink-0" />
                        <span>
                            Merge after {row.mergeAfter.repo}{row.mergeAfter.number !== null ? `#${row.mergeAfter.number}` : ''}.{row.mergeAfter.reason ? ` ${row.mergeAfter.reason}` : ''}
                        </span>
                    </p>
                )}

                {row.ci && (
                    <div className="border-t border-hair">
                        <div className="flex items-center gap-2 px-3 py-2">
                            <button type="button" aria-expanded={isExpanded} aria-label="Show checks" disabled={!hasChecks} onClick={() => setIsExpanded((value) => !value)} className="cursor-pointer disabled:cursor-default" data-testid={`ci-toggle-${testKey}`}>
                                <ChevronRight size={12} aria-hidden className={isExpanded ? 'rotate-90' : ''} />
                            </button>
                            <Badge tone={CI_TONE[row.ci.status] ?? 'neutral'}>{CI_LABEL[row.ci.status] ?? row.ci.status}</Badge>
                            {summary && <span className="text-[12px] text-muted">{summary}</span>}
                            {row.canAskToFix && (
                                <Button variant="secondary" pending={isBusy} onClick={onAskToFix} data-testid={`ask-to-fix-${testKey}`} className="ml-auto">Ask Claude to fix</Button>
                            )}
                        </div>
                        {isExpanded && hasChecks && (
                            <ul className="border-t border-hair px-3 py-1.5" data-testid={`ci-checks-${testKey}`}>
                                {row.ci.checks.map((check) => (
                                    <li key={check.name} className="py-1">
                                        <div className="flex items-center gap-2 text-[12.5px]">
                                            <span className={check.state === 'failing' ? 'text-fail' : check.state === 'passing' ? 'text-ok' : 'text-muted'} aria-hidden>{check.state === 'failing' ? '✕' : check.state === 'passing' ? '✓' : '…'}</span>
                                            <span className="font-mono text-body">{check.name}</span>
                                            <span className="ml-auto text-[12px] text-muted">{durationLabel(check.durationSeconds)}</span>
                                        </div>
                                        {check.excerpt && <p className="mt-1 whitespace-pre-wrap rounded-chip bg-fail-soft px-2 py-1.5 text-[12px] text-body" data-testid={`ci-excerpt-${testKey}`}>{check.excerpt}</p>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                {row.siblingBlock && <SiblingBlockPreview text={row.siblingBlock} testId={`sibling-block-${testKey}`} />}
            </div>
        </li>
    );
}
