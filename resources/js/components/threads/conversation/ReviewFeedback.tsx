import { cn } from '@geocodio/console-ui';
import { ExternalLink } from 'lucide-react';
import { Prose } from '@/components/Prose';
import type { ThreadReview } from '@/types/threads';

const STATES: Record<string, { label: string; className: string }> = {
    approved: { label: 'Approved', className: 'bg-ok-soft text-ok' },
    changes_requested: { label: 'Changes requested', className: 'bg-fail-soft text-fail' },
    commented: { label: 'Commented', className: 'bg-panel-2 text-muted' },
};

/** The review's result as a badge, as GitHub labels it. Nothing for loose PR comments, which have no result. */
export function ReviewStateBadge({ state }: { state: string }) {
    const known = STATES[state];

    if (!known) {
        return null;
    }

    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-pill py-px pr-2 pl-1.5 text-[11px] font-medium', known.className)} data-testid="review-state">
            <span className="size-1.5 rounded-pill bg-current" aria-hidden />
            {known.label}
        </span>
    );
}

function diffLineClass(line: string): string {
    if (line.startsWith('+')) {
        return 'bg-ok-soft';
    }

    if (line.startsWith('-')) {
        return 'bg-fail-soft';
    }

    return '';
}

/** A GitHub review as GitHub shows it: the summary, then one card per comment with its file, diff lines and a link back. */
export function ReviewFeedback({ review }: { review: ThreadReview }) {
    return (
        <div className="mt-1 flex flex-col gap-2.5" data-testid="review-feedback">
            {review.summaryHtml !== '' && <Prose html={review.summaryHtml} />}
            {review.comments.map((comment, index) => {
                const author = comment.author ?? review.reviewer;
                const avatarUrl = author ? `https://github.com/${encodeURIComponent(author)}.png?size=36` : null;

                return (
                    <div key={index} className="overflow-hidden rounded-card border border-hair bg-panel" data-testid="review-comment">
                        {comment.file && (
                            <div className="flex items-center gap-2 border-b border-hair bg-panel-2 px-2.5 py-1.5 text-[12px]">
                                <span className="min-w-0 flex-1 truncate font-mono [direction:rtl] text-left" title={comment.file}>
                                    {comment.file}
                                </span>
                                {comment.line !== null && <span className="shrink-0 text-faint">line {comment.line}</span>}
                                {comment.url && (
                                    <a href={comment.url} target="_blank" rel="noreferrer" className="inline-flex shrink-0 items-center gap-1 text-accent-text hover:underline" data-testid="review-comment-link">
                                        View on GitHub
                                        <ExternalLink className="size-3" aria-hidden />
                                    </a>
                                )}
                            </div>
                        )}
                        {comment.diff.length > 0 && (
                            <div className="overflow-x-auto font-mono text-[12px] leading-[1.6]">
                                {comment.diff.map((line, lineIndex) => (
                                    <div key={lineIndex} className={cn('flex min-w-max', diffLineClass(line))}>
                                        <span className={cn('w-6 shrink-0 text-center select-none', line.startsWith('+') ? 'text-ok' : line.startsWith('-') ? 'text-fail' : 'text-faint')}>{line.slice(0, 1).trim()}</span>
                                        <span className="pr-3 whitespace-pre">{line.slice(1)}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                        <div className={cn('flex gap-2 p-2.5', (comment.file || comment.diff.length > 0) && 'border-t border-hair')}>
                            {avatarUrl ? <img src={avatarUrl} alt="" className="mt-0.5 size-[18px] shrink-0 rounded-pill bg-panel-2" /> : <span className="mt-0.5 size-[18px] shrink-0 rounded-pill bg-panel-2" aria-hidden />}
                            <div className="min-w-0 flex-1">
                                {author && <div className="text-[12px] font-medium">{author}</div>}
                                <Prose html={comment.bodyHtml} />
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
