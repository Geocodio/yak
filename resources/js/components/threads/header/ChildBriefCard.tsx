import { KindIcon } from '@/components/threads/stack/KindIcon';
import type { ThreadPageData } from '@/types/threads';

/** The first item of a sub-thread's conversation: what its parent asked it to do. */
export function ChildBriefCard({ brief, parentTitle }: { brief: NonNullable<ThreadPageData['brief']>; parentTitle: string }) {
    return (
        <div className="mx-4 mt-4 rounded-card border border-hair bg-panel px-3 py-2.5 text-[13px] sm:mx-8" data-testid="child-brief">
            <div className="flex flex-wrap items-center gap-2">
                <KindIcon kind={brief.kind} />
                <span className="font-medium text-body">Brief from {parentTitle}</span>
                <span className="rounded-pill border border-hair bg-panel-2 px-2 py-0.5 text-[11px] text-muted">{brief.kind}</span>
                {brief.model && <span className="rounded-pill border border-hair bg-panel-2 px-2 py-0.5 text-[11px] text-muted">{brief.model}</span>}
                <span className="ml-auto text-[12px] text-muted">Started {new Date(brief.startedAt).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}</span>
            </div>
            <p className="mt-2 whitespace-pre-wrap text-body">{brief.text}</p>
            {brief.branch && <p className="mt-1.5 text-[12px] text-muted">Works on <span className="font-mono">{brief.branch}</span></p>}
        </div>
    );
}
