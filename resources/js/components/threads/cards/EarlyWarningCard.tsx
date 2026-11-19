import { Button } from '@geocodio/console-ui';
import { EarlyWarningNotice, CandidateCombobox } from '@/components/shared/EarlyWarningNotice';
import { decline, tag } from '@/routes/threads/co-owner';
import type { EarlyWarningData } from '@/types/coOwner';
import { useEarlyWarningCandidates } from './useEarlyWarningCandidates';

/** The early warning as the card above the composer, with the co-owner picker the requester answers it with. */
export function EarlyWarningCard({ threadId, warning }: { threadId: string; warning: EarlyWarningData }) {
    const { state, reload } = useEarlyWarningCandidates(threadId);

    return (
        <EarlyWarningNotice
            variant="card"
            warning={warning}
            routes={{ tag: tag.url(threadId), decline: decline.url(threadId) }}
            renderCandidates={({ disabled, onPick }) => {
                if (state.status === 'loading') {
                    return <span className="text-muted" data-testid="early-warning-checking" role="status">Checking who has write access to {warning.repoName}…</span>;
                }
                if (state.status === 'error') {
                    return (
                        <span className="flex items-center gap-2" data-testid="early-warning-candidates-error" role="alert">
                            <span className="text-[12.5px]">Could not load who has write access.</span>
                            <Button variant="secondary" onClick={reload}>Try again</Button>
                        </span>
                    );
                }

                return (
                    <>
                        <CandidateCombobox candidates={state.items} disabled={disabled} onPick={onPick} />
                        {!state.isComplete && <span className="text-[12px] text-muted" data-testid="early-warning-incomplete">Some people could not be checked.</span>}
                    </>
                );
            }}
        />
    );
}
