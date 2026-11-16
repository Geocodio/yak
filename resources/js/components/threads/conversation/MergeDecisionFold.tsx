import { ChevronRight } from 'lucide-react';

/** The `What was decided` fold of a merged sub-thread, with a header chip when a conflict was resolved. */
export function MergeDecisionFold({ decision, conflictResolved = true }: { decision: string | null; conflictResolved?: boolean }) {
    return (
        <div className="mt-2" data-testid="merge-decision">
            {conflictResolved && <span className="rounded-pill bg-warn-soft px-2 py-0.5 text-[11px] font-medium text-body" data-testid="merge-conflict-resolved">Merge conflict resolved</span>}
            {decision && (
                <details className="group mt-1.5">
                    <summary className="flex cursor-pointer list-none items-center gap-1 text-[12px] text-muted">
                        <ChevronRight size={12} className="transition-transform group-open:rotate-90" aria-hidden />
                        What was decided
                    </summary>
                    <p className="mt-1 whitespace-pre-wrap pl-4 text-[12.5px] text-body">{decision}</p>
                </details>
            )}
        </div>
    );
}
