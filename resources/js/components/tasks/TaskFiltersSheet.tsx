import { Button, Sheet } from '@geocodio/console-ui';
import { X } from 'lucide-react';
import { FilterMenu } from '@/components/tasks/FilterMenu';
import { STATUS, type TaskStatus } from '@/lib/status';
import type { TaskFilters } from '@/types/tasks';

type FilterPatch = Partial<Pick<TaskFilters, 'status' | 'source' | 'repo' | 'pr'>>;

/** The four task filters as full-width rows in a bottom sheet. Choosing a value applies it and closes the sheet. */
export function TaskFiltersSheet({
    open,
    onOpenChange,
    filters,
    onChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    filters: TaskFilters;
    onChange: (patch: FilterPatch) => void;
}) {
    const apply = (patch: FilterPatch) => {
        onChange(patch);
        onOpenChange(false);
    };
    const hasActiveFilters = filters.status !== '' || filters.source !== '' || filters.repo !== '' || filters.pr !== '';

    return (
        <Sheet open={open} onOpenChange={onOpenChange} side="bottom" title="Filters" data-testid="task-filters-sheet">
            <div className="flex flex-col gap-2 pb-2">
                <FilterMenu
                    block
                    testId="filter-status"
                    label="Status"
                    value={filters.status}
                    onChange={(status) => apply({ status })}
                    options={[{ value: '', label: 'All statuses' }, ...(Object.keys(STATUS) as TaskStatus[]).map((status) => ({ value: status, label: STATUS[status].label }))]}
                />
                {filters.tab === 'tasks' && (
                    <FilterMenu
                        block
                        testId="filter-source"
                        label="Source"
                        value={filters.source}
                        onChange={(source) => apply({ source })}
                        options={[{ value: '', label: 'All sources' }, ...filters.options.sources.map((source) => ({ value: source, label: source }))]}
                    />
                )}
                <FilterMenu
                    block
                    testId="filter-repo"
                    label="Repo"
                    value={filters.repo}
                    onChange={(repo) => apply({ repo })}
                    options={[{ value: '', label: 'All repos' }, ...filters.options.repos.map((repo) => ({ value: repo, label: repo }))]}
                />
                {filters.tab === 'tasks' && (
                    <FilterMenu
                        block
                        testId="filter-pr"
                        label="PR"
                        value={filters.pr}
                        onChange={(pr) => apply({ pr })}
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
                    <Button variant="tertiary" icon={<X size={12} />} data-testid="clear-filters" onClick={() => apply({ status: '', source: '', repo: '', pr: '' })}>
                        Clear filters
                    </Button>
                )}
            </div>
        </Sheet>
    );
}
