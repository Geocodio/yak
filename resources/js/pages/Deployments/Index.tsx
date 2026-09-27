import { Head, InfiniteScroll, Link, router, usePoll } from '@inertiajs/react';
import { Badge, cn, Menu, PageHeader, Spinner, StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, StatusPill, Th, Tooltip, Tr } from '@geocodio/console-ui';
import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { useRowEntrance } from '@/lib/useRowEntrance';
import { pollInfiniteScroll } from '@/lib/pollInfiniteScroll';
import { deployments as deploymentsIndex } from '@/routes';
import { show } from '@/routes/deployments';
import type { DeploymentFilters, DeploymentRow } from '@/types/deployments';
import type { PageProps } from '@/types/shared';

type Props = PageProps<{
    deployments: {
        data: DeploymentRow[];
        current_page: number;
        last_page: number;
    };
    filters: DeploymentFilters;
}>;

const STATUS_OPTIONS = [
    { value: 'active', label: 'Active' },
    { value: 'running', label: 'Running' },
    { value: 'hibernated', label: 'Hibernated' },
    { value: 'failed', label: 'Failed' },
    { value: 'all', label: 'All' },
];

export default function Index({ deployments, filters }: Props) {
    // Only `deployments` needs a poll -- see the matching comment in
    // Tasks/Index.tsx for why `only` (a partial reload) is required to keep
    // the scroll prop's merge-by-id behavior active instead of replacing the
    // whole list and losing any pages the user scrolled into.
    usePoll(15000, pollInfiniteScroll(['deployments']));
    const entranceClass = useRowEntrance(deployments.data.map((row) => row.id));

    const selected = STATUS_OPTIONS.find((o) => o.value === filters.status);

    const navigate = (status: string) => {
        router.get(deploymentsIndex.url(), { status }, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="Deployments" />
            <PageHeader crumbs={['Deployments']}>
                <Menu
                    trigger={
                        <span className="flex items-center gap-1.5 text-[12px]">
                            <span className="text-body">{selected ? selected.label : 'Status'}</span>
                            <ChevronDown size={12} className="text-faint" />
                        </span>
                    }
                    className="ml-4 h-7 rounded-pill px-2.5"
                    items={STATUS_OPTIONS.map((option) => ({
                        key: option.value,
                        label: option.label,
                        checked: option.value === filters.status,
                        onSelect: () => navigate(option.value),
                    }))}
                />
            </PageHeader>

            <div className="min-h-0 flex-1 overflow-auto">
                {deployments.data.length > 0 ? (
                    <InfiniteScroll
                        preserveUrl
                        key={filters.status}
                        data="deployments"
                        itemsElement="#deployments-table-body"
                        loading={() => (
                            <div className="flex items-center justify-center gap-2 py-4 text-[12px] text-muted">
                                <Spinner size="sm" />
                                Loading more deployments…
                            </div>
                        )}
                    >
                        <StackedTable className="w-full">
                            <StackedThead>
                                <Tr>
                                    <Th>Repository</Th>
                                    <Th>Branch</Th>
                                    <Th>Status</Th>
                                    <Th>Last accessed</Th>
                                    <Th>Preview URL</Th>
                                </Tr>
                            </StackedThead>
                            <StackedTbody id="deployments-table-body">
                                {deployments.data.map((deployment) => (
                                    <StackedTr key={deployment.id} data-testid={`deployment-row-${deployment.id}`} className={cn(deployment.longLived && 'bg-accent-soft/40', entranceClass(deployment.id))}>
                                        <StackedTd label="Repository" className="text-muted">
                                            {deployment.repoSlug}
                                        </StackedTd>
                                        <StackedTd label="Branch">
                                            <div className="flex items-center max-md:flex-wrap">
                                                <Link href={show.url(deployment.id)} className="font-medium text-accent-text hover:underline">
                                                    {deployment.branch}
                                                </Link>
                                                {deployment.longLived && (
                                                    <Tooltip label={`Hibernates after ${deployment.hibernatesAfter}`}>
                                                        <Badge tone="info" className="ml-2">
                                                            Long-lived
                                                            <span className="md:hidden"> · Hibernates after {deployment.hibernatesAfter}</span>
                                                        </Badge>
                                                    </Tooltip>
                                                )}
                                            </div>
                                        </StackedTd>
                                        <StackedTd label="Status">
                                            <StatusPill tone={deployment.tone} label={deployment.statusLabel} />
                                        </StackedTd>
                                        <StackedTd label="Last accessed" className="text-muted">
                                            {deployment.lastAccessedAgo ?? '—'}
                                        </StackedTd>
                                        <StackedTd label="Preview URL" className="max-md:break-all">
                                            <a href={`https://${deployment.hostname}`} target="_blank" rel="noopener" className={cn('text-accent-text hover:underline')}>
                                                {deployment.hostname}
                                            </a>
                                        </StackedTd>
                                    </StackedTr>
                                ))}
                            </StackedTbody>
                        </StackedTable>
                    </InfiniteScroll>
                ) : (
                    <div className="flex flex-col items-center gap-3 px-4 py-16 text-center text-[13px] text-muted sm:px-5">
                        <p>No deployments found.</p>
                    </div>
                )}
            </div>
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
