import { Deferred, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { CandidateCombobox, EarlyWarningNotice as SharedEarlyWarningNotice } from '@/components/shared/EarlyWarningNotice';
import { decline, tag } from '@/routes/tasks/co-owner';
import type { EarlyWarningCandidate, EarlyWarningData } from '@/types/coOwner';

/** The early warning after the request on the task page; the people to tag arrive as a deferred prop. */
export function EarlyWarningNotice({ taskId, warning }: { taskId: string; warning: EarlyWarningData }) {
    const page = usePage();
    const needsCandidates = warning.canPick && page.props.earlyWarningCandidates === undefined && page.deferredProps?.['early-warning'] === undefined;

    // The notice can arrive through a poll, which carries no deferred props, so the list is requested once here.
    useEffect(() => {
        if (needsCandidates) {
            router.reload({ only: ['earlyWarningCandidates'], preserveErrors: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <SharedEarlyWarningNotice
            warning={warning}
            routes={{ tag: tag.url(taskId), decline: decline.url(taskId) }}
            renderCandidates={({ disabled, onPick }) => (
                <Deferred data="earlyWarningCandidates" fallback={<span className="text-muted" data-testid="early-warning-checking">Checking who has write access to {warning.repoName}…</span>}>
                    <TaskCandidates disabled={disabled} onPick={onPick} />
                </Deferred>
            )}
        />
    );
}

function TaskCandidates({ disabled, onPick }: { disabled: boolean; onPick: (candidate: EarlyWarningCandidate) => void }) {
    const { earlyWarningCandidates } = usePage<{ earlyWarningCandidates: EarlyWarningCandidate[] }>().props;

    return <CandidateCombobox candidates={earlyWarningCandidates} disabled={disabled} onPick={onPick} />;
}
