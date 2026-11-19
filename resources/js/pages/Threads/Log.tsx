import { Head, InfiniteScroll, router, usePoll } from '@inertiajs/react';
import type { InfiniteScrollRef } from '@inertiajs/core';
import { Button, IconButton, Menu, PageHeader, Sheet, Spinner } from '@geocodio/console-ui';
import { ChevronDown, Search, SlidersHorizontal } from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';
import { openPalette } from '@/components/Sidebar';
import { CLEARED_FILTERS, ClearFiltersButton, hasActiveFilters, ThreadLogFilterControls, type FilterPatch } from '@/components/threads/log/ThreadLogFilters';
import { ThreadLogTable } from '@/components/threads/log/ThreadLogTable';
import { AppLayout } from '@/layouts/AppLayout';
import { pollInfiniteScroll } from '@/lib/pollInfiniteScroll';
import { log as threadLog } from '@/routes/threads';
import type { PageProps } from '@/types/shared';
import type { ThreadLogCounts, ThreadLogFacets, ThreadLogFilters, ThreadLogPage, ThreadLogSort } from '@/types/threadLog';

type Props = PageProps<{
    rows: ThreadLogPage;
    counts: ThreadLogCounts;
    filters: ThreadLogFilters;
    facets: ThreadLogFacets;
}>;

const SORT_OPTIONS: { label: string; sort: ThreadLogSort; direction: 'asc' | 'desc' }[] = [
    { label: 'Newest activity', sort: 'last_activity', direction: 'desc' },
    { label: 'Oldest activity', sort: 'last_activity', direction: 'asc' },
    { label: 'Source', sort: 'source', direction: 'asc' },
    { label: 'Started by', sort: 'starter', direction: 'asc' },
    { label: 'Repo', sort: 'repo', direction: 'asc' },
];

function activeFilterCount(filters: ThreadLogFilters): number {
    return [filters.status.length > 0, filters.sources.length > 0, filters.starters.length > 0, filters.repo !== '', filters.pull_request !== '', filters.needs_co_owner, filters.search !== ''].filter(Boolean).length;
}

function queryFor(filters: ThreadLogFilters) {
    return {
        status: filters.status.length > 0 ? filters.status : undefined,
        sources: filters.sources.length > 0 ? filters.sources : undefined,
        starters: filters.starters.length > 0 ? filters.starters : undefined,
        repo: filters.repo || undefined,
        pull_request: filters.pull_request || undefined,
        needs_co_owner: filters.needs_co_owner ? 1 : undefined,
        search: filters.search || undefined,
        sort: filters.sort === 'last_activity' && filters.direction === 'desc' ? undefined : filters.sort,
        direction: filters.sort === 'last_activity' && filters.direction === 'desc' ? undefined : filters.direction,
    };
}

export default function Log({ rows, counts, filters, facets }: Props) {
    // Poll only the rows and counts so the infinite scroll keeps the pages already loaded; see pollInfiniteScroll.
    usePoll(15000, pollInfiniteScroll(['rows', 'counts', 'facets']));
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [loadMoreFailed, setLoadMoreFailed] = useState(false);
    const scrollRef = useRef<InfiniteScrollRef>(null);

    const navigate = (patch: FilterPatch) => {
        router.get(threadLog.url(), queryFor({ ...filters, ...patch }), { preserveState: true, replace: true });
    };

    const onSort = (column: ThreadLogSort) => {
        const isDescendingDefault = column === 'last_activity';
        const direction = filters.sort === column ? (filters.direction === 'asc' ? 'desc' : 'asc') : isDescendingDefault ? 'desc' : 'asc';
        navigate({ sort: column, direction });
    };

    const isFiltered = hasActiveFilters(filters);
    const filterCount = activeFilterCount(filters);
    const sortLabel = SORT_OPTIONS.find((option) => option.sort === filters.sort && option.direction === filters.direction)?.label ?? 'Sort';
    const scrollKey = JSON.stringify(queryFor(filters));

    return (
        <>
            <Head title="Thread log" />
            <PageHeader crumbs={['Thread log']}>
                <span className="hidden text-[12px] text-muted sm:inline" data-testid="thread-log-subtitle">
                    Every thread, whoever started it. {counts.total} {counts.total === 1 ? 'thread' : 'threads'}.
                </span>
                <IconButton label="Search" onClick={openPalette} className="ml-auto h-8 w-8 shrink-0 lg:hidden">
                    <Search size={16} />
                </IconButton>
            </PageHeader>

            <div className="hidden flex-wrap items-center gap-2 border-b border-hair px-4 py-2 sm:px-5 lg:flex" data-testid="thread-log-filters">
                <ThreadLogFilterControls filters={filters} facets={facets} counts={counts} onChange={navigate} />
                <span className="flex-1" />
                {isFiltered && <ClearFiltersButton onClick={() => navigate(CLEARED_FILTERS)} />}
            </div>

            <div className="flex items-center gap-2 px-4 py-2.5 lg:hidden" data-testid="thread-log-filters-phone">
                <Button variant={filterCount > 0 ? 'secondary' : 'tertiary'} icon={<SlidersHorizontal size={13} />} data-testid="open-filters" onClick={() => setFiltersOpen(true)} className="rounded-pill">
                    Filters{filterCount > 0 ? ` ${filterCount}` : ''}
                </Button>
                <Menu
                    trigger={
                        <span className="flex items-center gap-1.5 text-[12px]">
                            {sortLabel}
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
                {isFiltered && <ClearFiltersButton onClick={() => navigate(CLEARED_FILTERS)} className="ml-auto" />}
            </div>
            <Sheet open={filtersOpen} onOpenChange={setFiltersOpen} side="bottom" title="Filters" data-testid="thread-log-filters-sheet">
                <div className="flex flex-col gap-2 pb-2">
                    <ThreadLogFilterControls filters={filters} facets={facets} counts={counts} onChange={navigate} block />
                </div>
            </Sheet>

            <div className="min-h-0 flex-1 overflow-auto" data-testid="thread-log-scroll">
                {rows.data.length > 0 ? (
                    <InfiniteScroll
                        ref={scrollRef}
                        preserveUrl
                        // A new filter or sort is a fresh visit; the key remounts the scroll so its next-page cursor restarts.
                        key={scrollKey}
                        data="rows"
                        itemsElement="#thread-log-body"
                        params={{
                            onHttpException: () => {
                                setLoadMoreFailed(true);
                                return false;
                            },
                            onNetworkError: () => {
                                setLoadMoreFailed(true);
                                return false;
                            },
                            onSuccess: () => setLoadMoreFailed(false),
                        }}
                        loading={() => (
                            <div className="flex items-center justify-center gap-2 py-4 text-[12px] text-muted" data-testid="thread-log-loading">
                                <Spinner size="sm" />
                                Loading more threads…
                            </div>
                        )}
                    >
                        <ThreadLogTable rows={rows.data} sort={filters.sort} direction={filters.direction} onSort={onSort} />
                        {loadMoreFailed && (
                            <div className="flex items-center justify-center gap-3 py-4 text-[12px] text-muted" role="alert" data-testid="thread-log-load-failed">
                                Could not load more threads.
                                <Button
                                    variant="secondary"
                                    data-testid="thread-log-retry"
                                    onClick={() => {
                                        setLoadMoreFailed(false);
                                        scrollRef.current?.fetchNext();
                                    }}
                                >
                                    Retry
                                </Button>
                            </div>
                        )}
                    </InfiniteScroll>
                ) : isFiltered ? (
                    <div className="flex flex-col items-center gap-3 px-5 py-16 text-center text-[13px] text-muted" data-testid="thread-log-empty-filtered">
                        <p className="text-[15px] text-body">No threads match these filters.</p>
                        <Button variant="secondary" onClick={() => navigate(CLEARED_FILTERS)}>
                            Clear filters
                        </Button>
                    </div>
                ) : (
                    <div className="px-5 py-16 text-center text-[13px] text-muted" data-testid="thread-log-empty">
                        <p>No threads yet.</p>
                    </div>
                )}
            </div>
        </>
    );
}

Log.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
