import { Head, Link, router, usePoll } from '@inertiajs/react';
import { Badge, Menu, StatusPill, Table, Tbody, Td, Th, Thead, Tooltip, Tr, cn } from '@geocodio/console-ui';
import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
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
    usePoll(15000);

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
                    <Table className="w-full">
                        <Thead>
                            <Tr>
                                <Th>Repository</Th>
                                <Th>Branch</Th>
                                <Th>Status</Th>
                                <Th>Last accessed</Th>
                                <Th>Preview URL</Th>
                            </Tr>
                        </Thead>
                        <Tbody>
                            {deployments.data.map((deployment) => (
                                <Tr key={deployment.id} data-testid={`deployment-row-${deployment.id}`} className={deployment.longLived ? 'bg-accent-soft/40' : undefined}>
                                    <Td className="text-muted">{deployment.repoSlug}</Td>
                                    <Td>
                                        <Link href={show.url(deployment.id)} className="font-medium text-accent-text hover:underline">
                                            {deployment.branch}
                                        </Link>
                                        {deployment.longLived && (
                                            <Tooltip label={`Hibernates after ${deployment.hibernatesAfter}`}>
                                                <Badge tone="info" className="ml-2">
                                                    Long-lived
                                                </Badge>
                                            </Tooltip>
                                        )}
                                    </Td>
                                    <Td>
                                        <StatusPill tone={deployment.tone} label={deployment.statusLabel} />
                                    </Td>
                                    <Td className="text-muted">{deployment.lastAccessedAgo ?? '—'}</Td>
                                    <Td>
                                        <a href={`https://${deployment.hostname}`} target="_blank" rel="noopener" className={cn('text-accent-text hover:underline')}>
                                            {deployment.hostname}
                                        </a>
                                    </Td>
                                </Tr>
                            ))}
                        </Tbody>
                    </Table>
                ) : (
                    <div className="flex flex-col items-center gap-3 px-5 py-16 text-center text-[13px] text-muted">
                        <p>No deployments found.</p>
                    </div>
                )}
            </div>

            {deployments.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-hair px-5 py-2" data-testid="deployment-pagination">
                    <button
                        type="button"
                        disabled={deployments.current_page <= 1}
                        className="text-[12px] text-muted disabled:opacity-40"
                        onClick={() => router.get(deploymentsIndex.url(), { status: filters.status, page: deployments.current_page - 1 }, { preserveState: true, replace: true })}
                    >
                        Previous
                    </button>
                    <span className="tnum text-[12px] text-muted">
                        Page {deployments.current_page} of {deployments.last_page}
                    </span>
                    <button
                        type="button"
                        disabled={deployments.current_page >= deployments.last_page}
                        className="text-[12px] text-muted disabled:opacity-40"
                        onClick={() => router.get(deploymentsIndex.url(), { status: filters.status, page: deployments.current_page + 1 }, { preserveState: true, replace: true })}
                    >
                        Next
                    </button>
                </div>
            )}
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
