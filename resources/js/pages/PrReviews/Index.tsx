import { Head, Link, router } from '@inertiajs/react';
import { Badge, Button, Menu, Table, Tbody, Td, Th, Thead, Toggle, Tr, cn } from '@geocodio/console-ui';
import { ChevronDown, MessageCircle } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { StatTile } from '@/components/costs/StatTile';
import { forPr } from '@/routes/pr-reviews';
import { repos, prReviews as prReviewsIndex } from '@/routes';
import type { PageProps } from '@/types/shared';
import type { PrReviewCommentPage, PrReviewFilters, PrReviewStats, PrReviewerStat } from '@/types/prReviews';

type Props = PageProps<{
    comments: PrReviewCommentPage;
    stats: PrReviewStats;
    reviewerStats: PrReviewerStat[];
    filters: PrReviewFilters;
}>;

const SEVERITY_TONE = { must_fix: 'fail', should_fix: 'warn', consider: 'neutral' } as const;

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

export default function Index({ comments, stats, reviewerStats, filters }: Props) {
    const navigate = (next: Partial<Pick<PrReviewFilters, 'repo' | 'severity' | 'category' | 'scope' | 'reviewer' | 'reactions' | 'sort' | 'dir' | 'tab'>> & { page?: number }) => {
        const reactions = next.reactions !== undefined ? next.reactions : filters.reactions;

        router.get(
            prReviewsIndex.url(),
            {
                repo: filters.repo,
                severity: filters.severity,
                category: filters.category,
                scope: filters.scope,
                reviewer: filters.reviewer,
                sort: filters.sort,
                dir: filters.dir,
                tab: filters.tab,
                ...next,
                reactions: reactions ? '1' : '',
            },
            { preserveState: true, replace: true },
        );
    };

    const onSort = (column: string) => {
        const dir = filters.sort === column && filters.dir === 'asc' ? 'desc' : 'asc';
        navigate({ sort: column, dir });
    };

    const SortHeader = ({ column, label }: { column: string; label: string }) => {
        const active = filters.sort === column;
        return (
            <Th>
                <button type="button" onClick={() => onSort(column)} className="inline-flex items-center gap-1 hover:text-body">
                    {label}
                    {active && <ChevronDown size={11} className={filters.dir === 'asc' ? 'rotate-180' : ''} />}
                </button>
            </Th>
        );
    };

    if (stats.reviews === 0) {
        return (
            <>
                <Head title="PR Reviews" />
                <PageHeader crumbs={['PR Reviews']} />
                <div className="flex flex-col items-center gap-3 px-5 py-16 text-center text-[13px] text-muted">
                    <MessageCircle size={32} className="text-faint" />
                    <p>No Yak reviews yet. Enable PR review on a repository to get started.</p>
                    <Link href={repos.url()} className="text-accent-text hover:underline">
                        Manage repositories
                    </Link>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="PR Reviews" />
            <PageHeader crumbs={['PR Reviews']}>
                <div className="ml-4 flex shrink-0 items-center gap-0.5 rounded-control bg-panel-2 p-0.5" data-testid="pr-review-tabs">
                    {(
                        [
                            { key: 'all', label: 'All comments' },
                            { key: 'by_reviewer', label: 'By reviewer' },
                        ] as const
                    ).map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            role="tab"
                            aria-selected={filters.tab === tab.key}
                            data-testid={`tab-${tab.key}`}
                            onClick={() => navigate({ tab: tab.key })}
                            className={cn(
                                'flex h-6 items-center gap-1.5 rounded-chip px-2.5 text-[12px] text-muted hover:text-body',
                                filters.tab === tab.key && 'bg-panel text-body shadow-card',
                            )}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>
            </PageHeader>

            <div className="grid grid-cols-2 gap-3 px-4 sm:px-5 py-3 md:grid-cols-4">
                <StatTile label="Reviews" value={String(stats.reviews)} sub="total" />
                <StatTile label="Suggestions" value={String(stats.suggestions)} sub="total" />
                <StatTile label="👍 rate" value={`${stats.thumbsUpRate}%`} sub="of reacted comments" />
                <StatTile label="Most 👎 category" value={stats.mostDownvotedCategory ?? '—'} sub="by downvotes" />
            </div>

            <div className="flex flex-wrap items-center gap-2 border-b border-hair px-4 sm:px-5 py-2" data-testid="pr-review-filters">
                <FilterMenu
                    label="Severity"
                    value={filters.severity}
                    onChange={(severity) => navigate({ severity })}
                    options={[
                        { value: '', label: 'All severities' },
                        { value: 'must_fix', label: 'Must fix' },
                        { value: 'should_fix', label: 'Should fix' },
                        { value: 'consider', label: 'Consider' },
                    ]}
                />
                <FilterMenu
                    label="Scope"
                    value={filters.scope}
                    onChange={(scope) => navigate({ scope })}
                    options={[
                        { value: '', label: 'All scopes' },
                        { value: 'full', label: 'Full' },
                        { value: 'incremental', label: 'Incremental' },
                    ]}
                />
                <FilterMenu
                    label="Repo"
                    value={filters.repo}
                    onChange={(repo) => navigate({ repo })}
                    options={[{ value: '', label: 'All repos' }, ...filters.options.repos.map((r) => ({ value: r, label: r }))]}
                />
                <FilterMenu
                    label="Category"
                    value={filters.category}
                    onChange={(category) => navigate({ category })}
                    options={[{ value: '', label: 'All categories' }, ...filters.options.categories.map((c) => ({ value: c, label: c }))]}
                />
                <FilterMenu
                    label="Reviewer"
                    value={filters.reviewer}
                    onChange={(reviewer) => navigate({ reviewer })}
                    options={[{ value: '', label: 'All reviewers' }, ...filters.options.reviewers.map((r) => ({ value: r, label: r }))]}
                />
                <div className="ml-1 flex items-center gap-2">
                    <Toggle checked={filters.reactions} onCheckedChange={(reactions) => navigate({ reactions })} label="With reactions only" />
                    <span className="text-[12px] text-muted">With reactions only</span>
                </div>
                {(filters.severity !== '' || filters.scope !== '' || filters.repo !== '' || filters.category !== '' || filters.reviewer !== '' || filters.reactions) && (
                    <Button
                        variant="link"
                        className="ml-1 text-[12px] text-muted"
                        data-testid="clear-filters"
                        onClick={() => navigate({ severity: '', scope: '', repo: '', category: '', reviewer: '', reactions: false })}
                    >
                        Clear
                    </Button>
                )}
            </div>

            <div className="min-h-0 flex-1 overflow-auto">
                {filters.tab === 'all' ? (
                    comments.data.length > 0 ? (
                        <Table className="w-full">
                            <Thead>
                                <Tr>
                                    <Th>PR</Th>
                                    <SortHeader column="file_path" label="File" />
                                    <SortHeader column="severity" label="Severity" />
                                    <SortHeader column="category" label="Category" />
                                    <Th>Reactions</Th>
                                </Tr>
                            </Thead>
                            <Tbody>
                                {comments.data.map((comment) => (
                                    <Tr key={comment.id} data-testid={`pr-review-comment-${comment.id}`}>
                                        <Td>
                                            {comment.repoSlug && comment.prNumber ? (
                                                <Link
                                                    href={forPr.url({ repoSlug: comment.repoSlug, prNumber: comment.prNumber })}
                                                    className="text-accent-text hover:underline"
                                                >
                                                    {comment.repoSlug}#{comment.prNumber}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </Td>
                                        <Td className="font-mono text-[12px] text-muted">
                                            {comment.filePath}:{comment.lineNumber}
                                        </Td>
                                        <Td>
                                            <Badge tone={SEVERITY_TONE[comment.severity as keyof typeof SEVERITY_TONE] ?? 'neutral'}>{comment.severity}</Badge>
                                        </Td>
                                        <Td className="text-muted">{comment.category}</Td>
                                        <Td className="text-muted">
                                            {comment.thumbsUp > 0 && <span className="mr-2">👍 {comment.thumbsUp}</span>}
                                            {comment.thumbsDown > 0 && <span>👎 {comment.thumbsDown}</span>}
                                        </Td>
                                    </Tr>
                                ))}
                            </Tbody>
                        </Table>
                    ) : (
                        <div className="flex flex-col items-center gap-3 px-5 py-16 text-center text-[13px] text-muted">
                            <p>No matching comments.</p>
                        </div>
                    )
                ) : (
                    <Table className="w-full">
                        <Thead>
                            <Tr>
                                <Th>Reviewer</Th>
                                <Th>Reactions</Th>
                                <Th>👍</Th>
                                <Th>👎</Th>
                            </Tr>
                        </Thead>
                        <Tbody>
                            {reviewerStats.length > 0 ? (
                                reviewerStats.map((r) => (
                                    <Tr key={r.login}>
                                        <Td>{r.login}</Td>
                                        <Td className="tnum">{r.total}</Td>
                                        <Td className="tnum">{r.up}</Td>
                                        <Td className="tnum">{r.down}</Td>
                                    </Tr>
                                ))
                            ) : (
                                <Tr>
                                    <Td colSpan={4} className="py-8 text-center text-muted">
                                        No reactions yet.
                                    </Td>
                                </Tr>
                            )}
                        </Tbody>
                    </Table>
                )}
            </div>

            {filters.tab === 'all' && comments.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-hair px-4 sm:px-5 py-2" data-testid="pr-review-pagination">
                    <Button variant="tertiary" disabled={comments.current_page <= 1} onClick={() => navigate({ page: comments.current_page - 1 })}>
                        Previous
                    </Button>
                    <span className="tnum text-[12px] text-muted">
                        Page {comments.current_page} of {comments.last_page}
                    </span>
                    <Button variant="tertiary" disabled={comments.current_page >= comments.last_page} onClick={() => navigate({ page: comments.current_page + 1 })}>
                        Next
                    </Button>
                </div>
            )}
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
