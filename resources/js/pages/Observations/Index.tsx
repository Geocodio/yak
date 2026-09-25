import { Head, Link, router, usePoll } from '@inertiajs/react';
import { Badge, Button, cn, Menu, PageHeader, Table, Tbody, Td, Th, Thead, Tr } from '@geocodio/console-ui';
import { ChevronDown, ExternalLink } from 'lucide-react';
import { AppLayout } from '@/layouts/AppLayout';
import { observations as observationsIndex } from '@/routes';
import type { PageProps } from '@/types/shared';
import type { ObservationFilters, ObservationPage } from '@/types/observations';

type Props = PageProps<{
    observations: ObservationPage;
    filters: ObservationFilters;
}>;

const OUTCOME_OPTIONS = [
    { value: '', label: 'All outcomes' },
    { value: 'acted', label: 'Acted' },
    { value: 'declined', label: 'Took no action' },
];

function FilterMenu({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    const selected = options.find((o) => o.value === value);

    return (
        <Menu
            trigger={
                <span className="flex items-center gap-1.5 text-[12px]">
                    <span className={value ? 'text-body' : 'text-muted'}>{selected ? selected.label : label}</span>
                    <ChevronDown size={12} className="text-faint" />
                </span>
            }
            className={cn('h-7 rounded-pill px-2.5', value && 'border-accent/40 bg-accent-soft')}
            items={options.map((option) => ({
                key: option.value || '__all__',
                label: option.label,
                checked: option.value === value,
                onSelect: () => onChange(option.value),
            }))}
        />
    );
}

export default function Index({ observations, filters }: Props) {
    usePoll(30000);

    const navigate = (next: { repo?: string; outcome?: string; page?: number }) => {
        router.get(
            observationsIndex.url(),
            { repo: filters.repo, outcome: filters.outcome, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const repoOptions = [
        { value: '', label: 'All repositories' },
        ...filters.options.repos.map((repo) => ({ value: repo, label: repo })),
    ];

    return (
        <AppLayout>
            <Head title="Observations" />

            <PageHeader crumbs={['Observations']}>
                <div className="flex items-center gap-2">
                    <FilterMenu
                        label="All repositories"
                        value={filters.repo}
                        options={repoOptions}
                        onChange={(repo) => navigate({ repo, page: 1 })}
                    />
                    <FilterMenu
                        label="All outcomes"
                        value={filters.outcome}
                        options={OUTCOME_OPTIONS}
                        onChange={(outcome) => navigate({ outcome, page: 1 })}
                    />
                </div>
            </PageHeader>

            <div className="flex-1 overflow-auto p-4 sm:p-5">
                <p className="mb-4 max-w-2xl text-[12px] text-muted">
                    What Yak noticed and what it decided to do about it, including the times it deliberately
                    took no action.
                </p>

                {observations.data.length === 0 ? (
                    <div className="rounded-lg border border-hair p-8 text-center text-[13px] text-muted">
                        Nothing recorded yet. Yak writes here whenever a scan reaches a decision.
                    </div>
                ) : (
                    <Table>
                        <Thead>
                            <Tr>
                                <Th className="w-24">When</Th>
                                <Th className="w-36">Outcome</Th>
                                <Th className="w-44">Repository</Th>
                                <Th>What happened</Th>
                                <Th className="w-24" />
                            </Tr>
                        </Thead>
                        <Tbody>
                            {observations.data.map((observation) => (
                                <Tr key={observation.id} data-testid="observation-row">
                                    <Td className="whitespace-nowrap text-muted" title={observation.createdTooltip}>
                                        {observation.createdAgo}
                                    </Td>
                                    <Td>
                                        <Badge tone={observation.outcome === 'acted' ? 'ok' : 'neutral'}>
                                            {observation.kindLabel}
                                        </Badge>
                                    </Td>
                                    <Td className="truncate text-muted">{observation.repo ?? '—'}</Td>
                                    <Td>
                                        <span className="text-body">{observation.summary}</span>
                                    </Td>
                                    <Td className="whitespace-nowrap text-right">
                                        {observation.taskUrl ? (
                                            <Link href={observation.taskUrl} className="text-[12px] text-accent">
                                                Task #{observation.taskId}
                                            </Link>
                                        ) : observation.referenceUrl ? (
                                            <a
                                                href={observation.referenceUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 text-[12px] text-accent"
                                            >
                                                Open <ExternalLink size={11} />
                                            </a>
                                        ) : null}
                                    </Td>
                                </Tr>
                            ))}
                        </Tbody>
                    </Table>
                )}

                {observations.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-between text-[12px] text-muted">
                        <span>
                            Page {observations.current_page} of {observations.last_page}
                        </span>
                        <div className="flex gap-2">
                            <Button
                                variant="secondary"
                                disabled={observations.current_page <= 1}
                                onClick={() => navigate({ page: observations.current_page - 1 })}
                            >
                                Previous
                            </Button>
                            <Button
                                variant="secondary"
                                disabled={observations.current_page >= observations.last_page}
                                onClick={() => navigate({ page: observations.current_page + 1 })}
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
