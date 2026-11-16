import { Badge } from '@geocodio/console-ui';
import { KeyRound } from 'lucide-react';
import { PersonAvatar } from '@/components/threads/environment/PersonAvatar';
import { LocationChip } from '@/components/threads/share/LocationChip';
import { VisibilityRow } from '@/components/threads/share/VisibilityRow';
import type { PanelProps } from '@/threads/panels';
import type { ThreadEnvironment } from '@/types/thread-changes';
import { OverviewGroup, OverviewList, OverviewRow, RowNote } from './OverviewRow';

function formatStartedAt(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/** Who can see the thread, who started it, and which Claude account it runs on. */
export function PeopleGroup({ thread, environment }: PanelProps & { environment: ThreadEnvironment }) {
    const { account, startedBy } = environment;

    return (
        <OverviewGroup title="People" testId="overview-people">
            <OverviewList>
                <VisibilityRow thread={thread} />
                {thread.startedOnDevice && (
                    <OverviewRow label="Started on" testId="environment-started-on">
                        <LocationChip thread={thread} />
                    </OverviewRow>
                )}
                {startedBy !== null && (
                    <OverviewRow label="Started by" testId="environment-started-by">
                        <PersonAvatar name={startedBy.name} />
                        {startedBy.name}
                        <RowNote>{formatStartedAt(startedBy.at)}</RowNote>
                    </OverviewRow>
                )}
                <OverviewRow label="Account" testId="environment-account" tip="The Claude account this thread runs on.">
                    {account === null ? (
                        <span className="text-muted">Not chosen yet</span>
                    ) : (
                        <>
                            <KeyRound size={12} className="shrink-0 text-muted" aria-hidden />
                            {account.name}
                            {account.isYours && <Badge tone="accent">Yours</Badge>}
                            <RowNote>Setup token. Fixed for this thread.</RowNote>
                        </>
                    )}
                </OverviewRow>
            </OverviewList>
        </OverviewGroup>
    );
}
