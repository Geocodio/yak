import { Head, router, usePoll } from '@inertiajs/react';
import { Button, cn, Menu, PageHeader } from '@geocodio/console-ui';
import { ChevronDown, Plus, SlidersHorizontal, X } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { FilterMenu } from '@/components/tasks/FilterMenu';
import { HoverPreview } from '@/components/tasks/HoverPreview';
import { NewTaskSheet } from '@/components/tasks/NewTaskSheet';
import { SetupCard } from '@/components/tasks/SetupCard';
import { TaskCardList } from '@/components/tasks/TaskCardList';
import { TaskFiltersSheet } from '@/components/tasks/TaskFiltersSheet';
import { TaskTable } from '@/components/tasks/TaskTable';
import { STATUS, type TaskStatus } from '@/lib/status';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { tasks as tasksIndex } from '@/routes';
import type { PageProps } from '@/types/shared';
import type { SetupCard as SetupCardData, TaskCounts, TaskFilters, TaskPage, TaskTab } from '@/types/tasks';

type Props = PageProps<{
    tasks: TaskPage;
    counts: TaskCounts;
    filters: TaskFilters;
    setupCard: SetupCardData;
    activeRepos: string[];
    openNew: boolean;
}>;

const TABS: { key: TaskTab; label: string }[] = [
    { key: 'tasks', label: 'Tasks' },
    { key: 'reviews', label: 'PR Reviews' },
    { key: 'setup', label: 'Setup' },
];

const SORT_OPTIONS = [
    { label: 'Newest', sort: 'created_at', direction: 'desc' },
    { label: 'Oldest', sort: 'created_at', direction: 'asc' },
    { label: 'Source', sort: 'source', direction: 'asc' },
    { label: 'Author', sort: 'author_name', direction: 'asc' },
    { label: 'Repo', sort: 'repo', direction: 'asc' },
] as const;

export default function Index({ tasks, counts, filters, setupCard, activeRepos, openNew }: Props) {
    usePoll(15000);
    const isDesktop = useMediaQuery('(min-width: 1024px)');
    const [sheetOpen, setSheetOpen] = useState(openNew);
    const [previewSrc, setPreviewSrc] = useState<string | null>(null);
    const [filtersOpen, setFiltersOpen] = useState(false);

    useEffect(() => {
        const onNewTask = () => setSheetOpen(true);
        window.addEventListener('yak:new-task', onNewTask);
        return () => window.removeEventListener('yak:new-task', onNewTask);
    }, []);

    const handleSheetOpenChange = (open: boolean) => {
        setSheetOpen(open);

        if (!open && openNew) {
            const url = new URL(window.location.href);
            url.searchParams.delete('new');
            window.history.replaceState(window.history.state, '', url.toString());
        }
    };

    const navigate = (
        next: Partial<Pick<TaskFilters, 'status' | 'source' | 'repo' | 'pr' | 'sort' | 'direction' | 'tab'>> & { page?: number },
    ) => {
        // `page` is only ever carried through explicitly (by the pager below) --
        // any filter, tab, or sort change omits it, which resets pagination to
        // page 1 on the backend.
        router.get(
            tasksIndex.url(),
            {
                status: filters.status,
                source: filters.source,
                repo: filters.repo,
                pr: filters.pr,
                sort: filters.sort,
                direction: filters.direction,
                tab: filters.tab,
                ...next,
            },
            { preserveState: true, replace: true },
        );
    };

    const onSort = (column: string) => {
        const direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
        navigate({ sort: column, direction });
    };

    const hasActiveFilters = filters.status !== '' || filters.source !== '' || filters.repo !== '' || filters.pr !== '';
    const activeFilterCount = [filters.status, filters.source, filters.repo, filters.pr].filter((value) => value !== '').length;

    const tabStrip = (
        <div className="flex shrink-0 items-center gap-0.5 rounded-control bg-panel-2 p-0.5 sm:ml-4" data-testid="task-tabs">
            {TABS.map((tab) => (
                <button
                    key={tab.key}
                    type="button"
                    role="tab"
                    aria-selected={filters.tab === tab.key}
                    data-testid={`tab-${tab.key}`}
                    onClick={() => navigate({ tab: tab.key, status: '', source: '', repo: '', pr: '' })}
                    className={cn(
                        'flex h-6 items-center gap-1.5 rounded-chip px-2.5 text-[12px] text-muted hover:text-body',
                        filters.tab === tab.key && 'bg-panel text-body shadow-card',
                    )}
                >
                    {tab.label}
                    <span className="tnum text-[11px] text-faint">{counts[tab.key]}</span>
                </button>
            ))}
        </div>
    );

    return (
        <>
            <Head title="Tasks" />
            <PageHeader
                crumbs={['Tasks']}
                actions={
                    <Button variant="primary" icon={<Plus size={13} />} onClick={() => setSheetOpen(true)} data-testid="new-task-trigger">
                        New task
                    </Button>
                }
            >
                {isDesktop && tabStrip}
            </PageHeader>

            {!isDesktop && (
                <div className="mx-4 mt-3 flex gap-0.5 rounded-control bg-panel-2 p-0.5" data-testid="task-tabs">
                    {TABS.map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            role="tab"
                            aria-selected={filters.tab === tab.key}
                            data-testid={`tab-${tab.key}`}
                            onClick={() => navigate({ tab: tab.key, status: '', source: '', repo: '', pr: '' })}
                            className={cn(
                                'flex h-7 flex-1 items-center justify-center gap-1.5 rounded-chip px-2.5 text-[12px] text-muted hover:text-body',
                                filters.tab === tab.key && 'bg-panel text-body shadow-card',
                            )}
                        >
                            {tab.label}
                            <span className="tnum text-[11px] text-faint">{counts[tab.key]}</span>
                        </button>
                    ))}
                </div>
            )}

            <SetupCard card={setupCard} />

            {isDesktop ? (
                <div className="flex flex-wrap items-center gap-2 border-b border-hair px-4 py-2 sm:px-5" data-testid="task-filters">
                    <FilterMenu
                        label="Status"
                        value={filters.status}
                        onChange={(status) => navigate({ status })}
                        options={[
                            { value: '', label: 'All statuses' },
                            ...(Object.keys(STATUS) as TaskStatus[]).map((status) => ({ value: status, label: STATUS[status].label })),
                        ]}
                    />
                    {filters.tab === 'tasks' && (
                        <FilterMenu
                            label="Source"
                            value={filters.source}
                            onChange={(source) => navigate({ source })}
                            options={[{ value: '', label: 'All sources' }, ...filters.options.sources.map((s) => ({ value: s, label: s }))]}
                        />
                    )}
                    <FilterMenu
                        label="Repo"
                        value={filters.repo}
                        onChange={(repo) => navigate({ repo })}
                        options={[{ value: '', label: 'All repos' }, ...filters.options.repos.map((r) => ({ value: r, label: r }))]}
                    />
                    {filters.tab === 'tasks' && (
                        <FilterMenu
                            label="PR"
                            value={filters.pr}
                            onChange={(pr) => navigate({ pr })}
                            options={[
                                { value: '', label: 'All PRs' },
                                { value: 'open', label: 'Open' },
                                { value: 'merged', label: 'Merged' },
                                { value: 'closed', label: 'Closed' },
                                { value: 'none', label: 'No PR' },
                            ]}
                        />
                    )}
                    {hasActiveFilters && (
                        <Button
                            variant="link"
                            icon={<X size={12} />}
                            className="ml-1 text-[12px] text-muted"
                            data-testid="clear-filters"
                            onClick={() => navigate({ status: '', source: '', repo: '', pr: '' })}
                        >
                            Clear
                        </Button>
                    )}
                </div>
            ) : (
                <>
                    <div className="flex items-center gap-2 px-4 py-2.5" data-testid="task-filters">
                        <Button
                            variant={activeFilterCount > 0 ? 'secondary' : 'tertiary'}
                            icon={<SlidersHorizontal size={13} />}
                            data-testid="open-filters"
                            onClick={() => setFiltersOpen(true)}
                            className="rounded-pill"
                        >
                            Filters{activeFilterCount > 0 ? ` ${activeFilterCount}` : ''}
                        </Button>
                        <Menu
                            trigger={
                                <span className="flex items-center gap-1.5 text-[12px]">
                                    {SORT_OPTIONS.find((option) => option.sort === filters.sort && option.direction === filters.direction)?.label ?? 'Sort'}
                                    <ChevronDown size={12} className="text-faint" />
                                </span>
                            }
                            className="h-8 rounded-pill px-3"
                            data-testid="open-sort"
                            items={SORT_OPTIONS.map((option) => ({
                                key: `${option.sort}-${option.direction}`,
                                label: option.label,
                                checked: option.sort === filters.sort && option.direction === filters.direction,
                                onSelect: () => navigate({ sort: option.sort, direction: option.direction }),
                            }))}
                        />
                        {hasActiveFilters && (
                            <Button variant="link" className="ml-auto text-[12px] text-muted" onClick={() => navigate({ status: '', source: '', repo: '', pr: '' })}>
                                Clear
                            </Button>
                        )}
                    </div>
                    <TaskFiltersSheet open={filtersOpen} onOpenChange={setFiltersOpen} filters={filters} onChange={(patch) => navigate(patch)} />
                </>
            )}

            <div className="min-h-0 flex-1 overflow-auto">
                {tasks.data.length > 0 ? (
                    isDesktop ? (
                        <TaskTable
                            tasks={tasks.data}
                            sort={filters.sort}
                            direction={filters.direction}
                            onSort={onSort}
                            onPreview={setPreviewSrc}
                        />
                    ) : (
                        <TaskCardList tasks={tasks.data} />
                    )
                ) : (
                    <div className="flex flex-col items-center gap-3 px-5 py-16 text-center text-[13px] text-muted">
                        <p>No tasks yet. Yak picks up work from your configured channels.</p>
                    </div>
                )}
            </div>

            {tasks.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-hair px-4 sm:px-5 py-2" data-testid="task-pagination">
                    <Button
                        variant="tertiary"
                        disabled={tasks.current_page <= 1}
                        onClick={() => navigate({ page: tasks.current_page - 1 })}
                    >
                        Previous
                    </Button>
                    <span className="tnum text-[12px] text-muted">
                        Page {tasks.current_page} of {tasks.last_page}
                    </span>
                    <Button
                        variant="tertiary"
                        disabled={tasks.current_page >= tasks.last_page}
                        onClick={() => navigate({ page: tasks.current_page + 1 })}
                    >
                        Next
                    </Button>
                </div>
            )}

            <HoverPreview src={previewSrc} />

            <NewTaskSheet open={sheetOpen} onOpenChange={handleSheetOpenChange} repoOptions={activeRepos} />
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
