import { Popover } from '@base-ui/react/popover';
import { Button, Checkbox, cn } from '@geocodio/console-ui';
import { ChevronDown, X } from 'lucide-react';
import { useState } from 'react';
import { FilterMenu } from '@/components/shared/FilterMenu';
import { THREAD_LOG_STATUSES, type ThreadLogCounts, type ThreadLogFacets, type ThreadLogFilters } from '@/types/threadLog';

export type FilterPatch = Partial<ThreadLogFilters>;

type Option = { key: string; label: string; count?: number };

const PULL_REQUEST_OPTIONS = [
    { value: '', label: 'All PRs' },
    { value: 'open', label: 'Open' },
    { value: 'merged', label: 'Merged' },
    { value: 'closed', label: 'Closed' },
    { value: 'held', label: 'Held for a co-owner' },
    { value: 'none', label: 'No PR' },
];

export function hasActiveFilters(filters: ThreadLogFilters): boolean {
    return (
        filters.status.length > 0 ||
        filters.sources.length > 0 ||
        filters.starters.length > 0 ||
        filters.repo !== '' ||
        filters.pull_request !== '' ||
        filters.needs_co_owner ||
        filters.search !== ''
    );
}

export const CLEARED_FILTERS: FilterPatch = { status: [], sources: [], starters: [], repo: '', pull_request: '', needs_co_owner: false, search: '' };

/** A filter that picks several values: a trigger with a summary and a popover of checkboxes. */
function MultiSelectFilter({
    label,
    allLabel,
    options,
    picked,
    onChange,
    testId,
    block,
}: {
    label: string;
    allLabel: string;
    options: Option[];
    picked: string[];
    onChange: (next: string[]) => void;
    testId: string;
    block?: boolean;
}) {
    const pickedLabels = picked.map((key) => options.find((option) => option.key === key)?.label ?? key);
    const summary = pickedLabels.length === 0 ? allLabel : `${label}: ${pickedLabels.join(', ')}`;
    const toggled = (key: string) => (picked.includes(key) ? picked.filter((item) => item !== key) : [...picked, key]);

    return (
        <Popover.Root>
            <Popover.Trigger
                data-testid={testId}
                className={cn(
                    'flex h-7 max-w-[260px] items-center gap-1.5 rounded-pill border border-hair bg-panel px-2.5 text-[12px] hover:bg-panel-2',
                    picked.length > 0 && 'border-accent/40 bg-accent-soft',
                    block && 'h-10 w-full max-w-none justify-between',
                )}
            >
                <span className={cn('truncate', picked.length > 0 ? 'text-body' : 'text-muted')}>{summary}</span>
                <ChevronDown size={12} className="shrink-0 text-faint" />
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Positioner align="start" sideOffset={4} className="z-50 outline-none">
                    <Popover.Popup className="ui-floating max-h-[70vh] w-[260px] max-w-[calc(100vw-24px)] overflow-y-auto rounded-card border border-hair bg-panel p-1 shadow-overlay outline-none" data-testid={`${testId}-menu`}>
                        {options.length === 0 && <p className="px-2 py-2 text-[12px] text-muted">Nothing to pick yet.</p>}
                        {options.map((option) => (
                            <div key={option.key} className="flex items-center gap-2 rounded-control px-2 py-1 hover:bg-panel-2">
                                <Checkbox
                                    checked={picked.includes(option.key)}
                                    onCheckedChange={() => onChange(toggled(option.key))}
                                    label={option.label}
                                    className="min-w-0 flex-1"
                                    data-testid={`${testId}-${option.key}`}
                                />
                                {option.count !== undefined && <span className="tnum text-[12px] text-muted">{option.count}</span>}
                            </div>
                        ))}
                    </Popover.Popup>
                </Popover.Positioner>
            </Popover.Portal>
        </Popover.Root>
    );
}

function SearchField({ value, onSubmit, block }: { value: string; onSubmit: (search: string) => void; block?: boolean }) {
    const [draft, setDraft] = useState(value);
    const submit = () => draft.trim() !== value && onSubmit(draft.trim());

    return (
        <input
            type="search"
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            onBlur={submit}
            onKeyDown={(event) => event.key === 'Enter' && submit()}
            placeholder="Search titles"
            aria-label="Search titles"
            data-testid="thread-log-search"
            className={cn('h-7 w-44 rounded-pill border border-hair bg-panel px-3 text-[12px] text-body placeholder:text-faint', block && 'h-10 w-full')}
        />
    );
}

/** Every thread log filter. Desktop lays them in a row; the phone sheet stacks them with `block`. */
export function ThreadLogFilterControls({
    filters,
    facets,
    counts,
    onChange,
    block,
}: {
    filters: ThreadLogFilters;
    facets: ThreadLogFacets;
    counts: ThreadLogCounts;
    onChange: (patch: FilterPatch) => void;
    block?: boolean;
}) {
    const showChip = counts.needsCoOwner > 0 || filters.needs_co_owner;

    return (
        <>
            <SearchField key={filters.search} value={filters.search} onSubmit={(search) => onChange({ search })} block={block} />
            <MultiSelectFilter
                block={block}
                testId="filter-status"
                label="Status"
                allLabel="All statuses"
                options={THREAD_LOG_STATUSES.map((status) => ({ key: status.key, label: status.label }))}
                picked={filters.status}
                onChange={(status) => onChange({ status })}
            />
            <MultiSelectFilter
                block={block}
                testId="filter-source"
                label="Source"
                allLabel="All sources"
                options={[...facets.sources, ...filters.sources.filter((key) => !facets.sources.some((source) => source.key === key)).map((key) => ({ key, label: key, count: 0 }))]}
                picked={filters.sources}
                onChange={(sources) => onChange({ sources })}
            />
            <MultiSelectFilter
                block={block}
                testId="filter-starter"
                label="Started by"
                allLabel="Started by: anyone"
                options={[
                    ...facets.starters.map((starter) => ({ key: starter.key, label: starter.name, count: starter.count })),
                    ...filters.starters.filter((key) => !facets.starters.some((starter) => starter.key === key)).map((key) => ({ key, label: key, count: 0 })),
                ]}
                picked={filters.starters}
                onChange={(starters) => onChange({ starters })}
            />
            <FilterMenu
                block={block}
                testId="filter-repo"
                label="Repo"
                value={filters.repo}
                onChange={(repo) => onChange({ repo })}
                options={[{ value: '', label: 'All repos' }, ...facets.repos.map((repo) => ({ value: repo, label: repo }))]}
            />
            <FilterMenu block={block} testId="filter-pull-request" label="PR" value={filters.pull_request} onChange={(pull_request) => onChange({ pull_request })} options={PULL_REQUEST_OPTIONS} />
            {showChip && (
                <button
                    type="button"
                    data-testid="needs-co-owner-chip"
                    aria-pressed={filters.needs_co_owner}
                    onClick={() => onChange({ needs_co_owner: !filters.needs_co_owner })}
                    className={cn(
                        'flex h-7 items-center gap-1.5 rounded-pill border border-hair px-2.5 text-[12px] text-muted hover:text-body',
                        filters.needs_co_owner && 'bg-panel-2 text-body',
                        block && 'h-10 w-full',
                    )}
                >
                    <span className="h-1.5 w-1.5 rounded-full bg-warn" />
                    Needs a co-owner
                    <span className="tnum text-[11px] text-faint">{counts.needsCoOwner}</span>
                </button>
            )}
        </>
    );
}

export function ClearFiltersButton({ onClick, className }: { onClick: () => void; className?: string }) {
    return (
        <Button variant="link" icon={<X size={12} />} className={cn('text-[12px] text-muted', className)} data-testid="clear-filters" onClick={onClick}>
            Clear filters
        </Button>
    );
}
