import { GitPullRequest, Mail, MessageSquare, ShieldAlert, Terminal, Zap } from 'lucide-react';
import type { ComponentType } from 'react';
import type { ThreadMessageVia } from '@/types/threads';

const SOURCE_MARK: Record<string, ComponentType<{ className?: string; 'aria-hidden'?: boolean }>> = {
    slack: MessageSquare,
    linear: Zap,
    sentry: ShieldAlert,
    github: GitPullRequest,
    email: Mail,
};

/** A source with its mark and label, linked to where the work came from when the source has a page. */
export function SourceLink({ source, testId = 'source-link' }: { source: ThreadMessageVia; testId?: string }) {
    const Mark = SOURCE_MARK[source.key] ?? Terminal;
    const label = source.reference ? `${source.label} ${source.reference}` : source.label;
    const content = (
        <>
            <Mark className="size-3 shrink-0" aria-hidden />
            {label}
        </>
    );
    const classes = 'inline-flex items-center gap-1 text-muted';

    return source.url ? (
        <a href={source.url} target="_blank" rel="noreferrer" className={`${classes} underline-offset-2 hover:text-body hover:underline`} data-testid={testId}>
            {content}
        </a>
    ) : (
        <span className={classes} data-testid={testId}>
            {content}
        </span>
    );
}
