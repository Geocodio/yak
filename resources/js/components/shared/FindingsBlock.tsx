import { Badge, cn } from '@geocodio/console-ui';
import { ChevronRight } from 'lucide-react';
import { useState, Fragment } from 'react';
import { Prose } from '@/components/Prose';
import type { FindingComment, FindingsData } from '@/types/tasks';

const SEVERITY_TONE = { must_fix: 'fail', should_fix: 'warn', consider: 'neutral' } as const;
const SEVERITY_LABEL = { must_fix: 'Must fix', should_fix: 'Should fix', consider: 'Consider' } as const;

function CommentRow({ comment }: { comment: FindingComment }) {
    const [open, setOpen] = useState(false);
    const tone = SEVERITY_TONE[comment.severity as keyof typeof SEVERITY_TONE] ?? 'neutral';
    const label = SEVERITY_LABEL[comment.severity as keyof typeof SEVERITY_LABEL] ?? comment.severity;

    return (
        <div className="border-b border-hair last:border-0">
            <button type="button" onClick={() => setOpen((o) => !o)} className="flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-panel-2">
                <ChevronRight size={11} className={cn('mt-0.5 shrink-0 text-faint transition-transform', open && 'rotate-90')} />
                <Badge tone={tone}>{label}</Badge>
                <span className="min-w-0 flex-1 truncate text-[12px] text-muted">
                    {comment.path}
                    {comment.line ? `:${comment.line}` : ''}
                </span>
            </button>
            {open && (
                <div className="px-3 pb-3">
                    <Prose html={comment.bodyHtml} className="text-[12px]" />
                </div>
            )}
        </div>
    );
}

type Risk = NonNullable<NonNullable<FindingsData>['riskAssessment']>;

function RiskAssessment({ risk, className }: { risk: Risk; className?: string }) {
    return (
        <div className={cn('px-3 py-2 text-[12px]', className)} data-testid="review-risk-assessment">
            <p className="font-medium text-body">GitHub review: {risk.event === 'APPROVE' ? 'Approved' : risk.event === 'REQUEST_CHANGES' ? 'Changes requested' : 'Comment only'}</p>
            {risk.mode === 'shadow' && (
                <p className="text-muted">Shadow mode. Policy recommendation: {risk.candidate.replace(/_/g, ' ').toLowerCase()}.</p>
            )}
            {risk.mode === 'off' && <p className="text-muted">Automatic approval is disabled.</p>}
            {risk.risk_score != null && <p className="text-muted">Risk: {risk.risk_score}/100 (higher means more risk)</p>}
            {risk.model_confidence != null && <p className="text-muted">Model confidence: {risk.model_confidence}/100. Self-reported, not a measured probability.</p>}
            {risk.reasons.length > 0 && (
                <ul className="mt-2 list-disc space-y-1 pl-4 text-muted">
                    {risk.reasons.map((reason, index) => <li key={index}>{reason}</li>)}
                </ul>
            )}
            <details className="mt-2 text-muted">
                <summary className="cursor-pointer">Evidence</summary>
                {risk.profile_version != null && <p className="mt-2 break-all">Profile: {risk.profile_version}</p>}
                <pre className="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-words text-[11px]">{JSON.stringify(
                    risk.level !== undefined
                        ? { level: risk.level, classification: risk.classification, concerns_version: risk.concerns_version, observed: risk.observed }
                        : { signals: risk.signals, observed: risk.observed, score_components: risk.score_components },
                    null, 2)}</pre>
            </details>
        </div>
    );
}

/** The first line or sentence of a rendered finding, as plain text. */
function firstSentence(html: string): string {
    const text = (typeof document === 'undefined' ? html : (new DOMParser().parseFromString(html, 'text/html').body.textContent ?? '')).trim();
    const line = text.split('\n')[0].trim();
    const sentence = line.split(/(?<=[.!?])\s/)[0];

    return sentence.length > 220 ? `${sentence.slice(0, 217)}...` : sentence;
}

function FindingsTable({ findings }: { findings: NonNullable<FindingsData> }) {
    const [openRows, setOpenRows] = useState<Set<number>>(new Set());
    const toggle = (index: number) =>
        setOpenRows((previous) => {
            const next = new Set(previous);
            if (!next.delete(index)) {
                next.add(index);
            }

            return next;
        });

    return (
        <div className="space-y-3" data-testid="findings-table-block">
            <Prose html={findings.summaryHtml} className="text-[13px]" />
            <div className="flex flex-wrap gap-2" data-testid="findings-counts">
                <Badge tone="fail">{findings.counts.mustFix} must fix</Badge>
                <Badge tone="warn">{findings.counts.shouldFix} should fix</Badge>
                <Badge tone="neutral">{findings.counts.consider} consider</Badge>
            </div>
            {findings.comments.length > 0 && (
                <div className="overflow-x-auto" data-testid="findings-table">
                    <table className="w-full min-w-[640px] border-collapse text-left text-[12px]">
                        <thead>
                            <tr className="border-b border-hair text-[11px] uppercase tracking-wide text-faint">
                                <th className="py-1.5 pr-3 font-medium">Severity</th>
                                <th className="py-1.5 pr-3 font-medium">Category</th>
                                <th className="py-1.5 pr-3 font-medium">File</th>
                                <th className="py-1.5 font-medium">Finding</th>
                            </tr>
                        </thead>
                        <tbody>
                            {findings.comments.map((comment, index) => {
                                const isOpen = openRows.has(index);

                                return (
                                    <Fragment key={index}>
                                        <tr className="cursor-pointer border-b border-hair align-top hover:bg-panel-2" onClick={() => toggle(index)} data-testid="finding-row" aria-expanded={isOpen}>
                                            <td className="py-2 pr-3">
                                                <Badge tone={SEVERITY_TONE[comment.severity as keyof typeof SEVERITY_TONE] ?? 'neutral'}>
                                                    {(SEVERITY_LABEL[comment.severity as keyof typeof SEVERITY_LABEL] ?? comment.severity).toLowerCase()}
                                                </Badge>
                                            </td>
                                            <td className="py-2 pr-3 text-muted">{comment.category}</td>
                                            <td className="py-2 pr-3 font-mono text-muted">
                                                {comment.path}
                                                {comment.line ? `:${comment.line}` : ''}
                                            </td>
                                            <td className="py-2 text-body">{firstSentence(comment.bodyHtml)}</td>
                                        </tr>
                                        {isOpen && (
                                            <tr className="border-b border-hair" data-testid="finding-body">
                                                <td colSpan={4} className="pb-3 pt-1">
                                                    <Prose html={comment.bodyHtml} className="text-[12px]" />
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
            {findings.riskAssessment && (
                <details className="rounded-[8px] border border-hair" data-testid="review-risk-details">
                    <summary className="cursor-pointer px-3 py-2 text-[12px] font-medium text-body">GitHub review and risk</summary>
                    <RiskAssessment risk={findings.riskAssessment} className="border-t border-hair" />
                </details>
            )}
        </div>
    );
}

export function FindingsBlock({ findings, variant = 'accordion' }: { findings: FindingsData; variant?: 'accordion' | 'table' }) {
    if (!findings) {
        return null;
    }

    if (variant === 'table') {
        return <FindingsTable findings={findings} />;
    }

    return (
        <div className="mt-3 rounded-card border border-hair bg-panel shadow-card" data-testid="findings-block">
            <div className="flex items-center gap-2 border-b border-hair px-3 py-2">
                <span className="text-[12px] font-medium text-body">Model verdict: {findings.verdict.replace(/_/g, ' ')}</span>
                <span className="ml-auto flex items-center gap-2 text-[11px] text-faint">
                    {findings.counts.mustFix > 0 && <span className="text-fail">{findings.counts.mustFix} must-fix</span>}
                    {findings.counts.shouldFix > 0 && <span className="text-warn">{findings.counts.shouldFix} should-fix</span>}
                    {findings.counts.consider > 0 && <span>{findings.counts.consider} consider</span>}
                </span>
            </div>
            {findings.riskAssessment && <RiskAssessment risk={findings.riskAssessment} className="border-b border-hair" />}
            <div className="px-3 py-2">
                <Prose html={findings.summaryHtml} className="text-[12px]" />
            </div>
            {findings.comments.length > 0 && (
                <div className="border-t border-hair">
                    {findings.comments.map((comment, index) => (
                        <CommentRow key={index} comment={comment} />
                    ))}
                </div>
            )}
        </div>
    );
}
