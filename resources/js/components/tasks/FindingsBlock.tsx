import { Badge, cn } from '@geocodio/console-ui';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
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

export function FindingsBlock({ findings }: { findings: FindingsData }) {
    if (!findings) {
        return null;
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
            {findings.riskAssessment && (
                <div className="border-b border-hair px-3 py-2 text-[12px]" data-testid="review-risk-assessment">
                    <p className="font-medium text-body">GitHub review: {findings.riskAssessment.event === 'APPROVE' ? 'Approved' : findings.riskAssessment.event === 'REQUEST_CHANGES' ? 'Changes requested' : 'Comment only'}</p>
                    {findings.riskAssessment.mode === 'shadow' && (
                        <p className="text-muted">Shadow mode. Policy recommendation: {findings.riskAssessment.candidate.replace(/_/g, ' ').toLowerCase()}.</p>
                    )}
                    {findings.riskAssessment.mode === 'off' && <p className="text-muted">Automatic approval is disabled.</p>}
                    <p className="text-muted">Risk: {findings.riskAssessment.risk_score === null ? 'Unknown' : `${findings.riskAssessment.risk_score}/100 (higher means more risk)`}</p>
                    <p className="text-muted">Model confidence: {findings.riskAssessment.model_confidence === null ? 'Unknown' : `${findings.riskAssessment.model_confidence}/100`}. Self-reported, not a measured probability.</p>
                    {findings.riskAssessment.reasons.length > 0 && (
                        <ul className="mt-2 list-disc space-y-1 pl-4 text-muted">
                            {findings.riskAssessment.reasons.map((reason, index) => <li key={index}>{reason}</li>)}
                        </ul>
                    )}
                    <details className="mt-2 text-muted">
                        <summary className="cursor-pointer">Signals and evidence</summary>
                        <p className="mt-2 break-all">Profile: {findings.riskAssessment.profile_version ?? 'No approved profile'}</p>
                        <pre className="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-words text-[11px]">{JSON.stringify({ signals: findings.riskAssessment.signals, observed: findings.riskAssessment.observed, score_components: findings.riskAssessment.score_components }, null, 2)}</pre>
                    </details>
                </div>
            )}
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
