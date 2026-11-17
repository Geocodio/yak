import { Skeleton, cn } from '@geocodio/console-ui';
import { SourceLink } from '@/components/threads/header/SourceLink';
import type { PanelProps } from '@/threads/panels';
import type { ThreadRequest } from '@/types/threads';
import { OverviewGroup, OverviewList, OverviewRow } from './OverviewRow';

const PILL_TONE: Record<ThreadRequest['status']['tone'], string> = {
    ok: 'bg-ok-soft text-ok',
    warn: 'bg-warn-soft text-warn',
    bad: 'bg-fail-soft text-fail',
    info: 'bg-info-soft text-info',
    neutral: 'bg-panel-2 text-muted',
};

/** Where the request came from, who started it, who is responsible, and how far it got. */
export function RequestGroup({ request }: PanelProps) {
    if (request === undefined) {
        return (
            <OverviewGroup title="Request" testId="overview-request-loading">
                <div className="flex flex-col gap-3">
                    <Skeleton className="h-4 w-2/3" />
                    <Skeleton className="h-4 w-1/2" />
                    <Skeleton className="h-4 w-3/5" />
                </div>
            </OverviewGroup>
        );
    }

    const { source, starter, responsibleName, status } = request;

    return (
        <OverviewGroup title="Request" testId="overview-request">
            <OverviewList>
                <OverviewRow label="Source" testId="request-source">
                    {source.key === 'dashboard' ? 'Dashboard' : <SourceLink source={source} />}
                </OverviewRow>
                <OverviewRow label="Started by" testId="request-starter">
                    {starter.name}
                </OverviewRow>
                <OverviewRow label="Responsible" testId="request-responsible">
                    {responsibleName ?? <span className="text-muted">Nobody yet</span>}
                </OverviewRow>
                <OverviewRow label="Status" testId="request-status">
                    <span className={cn('inline-flex items-center rounded-pill px-2 py-0.5 text-[12px] font-medium', PILL_TONE[status.tone])}>{status.label}</span>
                </OverviewRow>
            </OverviewList>
        </OverviewGroup>
    );
}
