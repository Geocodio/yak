import { Head, InfiniteScroll, Link, router, usePoll } from '@inertiajs/react';
import { Badge, cn, Menu, PageHeader, Spinner, StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, Th, Tr } from '@geocodio/console-ui';
import { ChevronDown, ExternalLink } from 'lucide-react';
import { AppLayout } from '@/layouts/AppLayout';
import { pollInfiniteScroll } from '@/lib/pollInfiniteScroll';
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
    // Only `observations` needs a poll -- see the matching comment in
    // Tasks/Index.tsx for why `only` (a partial reload) is required to keep
    // the scroll prop's merge-by-id behavior active instead of replacing the
    // whole list and losing any pages the user scrolled into.
    usePoll(30000, pollInfiniteScroll(['observations']));

    const navigate = (next: { repo?: string; outcome?: string }) => {
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
                        onChange={(repo) => navigate({ repo })}
                    />
                    <FilterMenu
                        label="All outcomes"
                        value={filters.outcome}
                        options={OUTCOME_OPTIONS}
                        onChange={(outcome) => navigate({ outcome })}
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
                    <InfiniteScroll
                        preserveUrl
                        key={`${filters.repo}-${filters.outcome}`}
                        data="observations"
                        itemsElement="#observations-table-body"
                        loading={() => (
                            <div className="flex items-center justify-center gap-2 py-4 text-[12px] text-muted">
                                <Spinner size="sm" />
                                Loading more observations…
                            </div>
                        )}
                    >
                        <StackedTable>
                            <StackedThead>
                                <Tr>
                                    <Th className="w-24">When</Th>
                                    <Th className="w-36">Outcome</Th>
                                    <Th className="w-44">Repository</Th>
                                    <Th>What happened</Th>
                                    <Th className="w-24" />
                                </Tr>
                            </StackedThead>
                            <StackedTbody id="observations-table-body">
                                {observations.data.map((observation) => (
                                    <StackedTr key={observation.id} data-testid="observation-row">
                                        <StackedTd label="When" className="md:whitespace-nowrap text-muted" title={observation.createdTooltip}>
                                            {observation.createdAgo}
                                        </StackedTd>
                                        <StackedTd label="Outcome">
                                            <Badge tone={observation.outcome === 'acted' ? 'ok' : 'neutral'}>
                                                {observation.kindLabel}
                                            </Badge>
                                        </StackedTd>
                                        <StackedTd label="Repository" className="truncate text-muted">
                                            {observation.repo ?? '—'}
                                        </StackedTd>
                                        <StackedTd label="What happened">
                                            <span className="text-body">{observation.summary}</span>
                                        </StackedTd>
                                        <StackedTd className="md:whitespace-nowrap md:text-right">
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
                                        </StackedTd>
                                    </StackedTr>
                                ))}
                            </StackedTbody>
                        </StackedTable>
                    </InfiniteScroll>
                )}
            </div>
        </AppLayout>
    );
}
