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
                <span className="text-[12px] font-medium text-body capitalize">{findings.verdict.replace(/_/g, ' ')}</span>
                <span className="ml-auto flex items-center gap-2 text-[11px] text-faint">
                    {findings.counts.mustFix > 0 && <span className="text-fail">{findings.counts.mustFix} must-fix</span>}
                    {findings.counts.shouldFix > 0 && <span className="text-warn">{findings.counts.shouldFix} should-fix</span>}
                    {findings.counts.consider > 0 && <span>{findings.counts.consider} consider</span>}
                </span>
            </div>
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
