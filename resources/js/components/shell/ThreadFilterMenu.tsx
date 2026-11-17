import { Popover } from '@base-ui/react/popover';
import { Button, Checkbox } from '@geocodio/console-ui';
import { ListFilter } from 'lucide-react';
import type { ThreadListFacets } from '@/types/shell';

type Option = { key: string; label: string; count: number };

/** The readable part of a picked key that no row carries any more. */
export function filterKeyLabel(key: string): string {
    return key.startsWith('name:') ? key.slice(5) : key;
}

function withPicked(options: Option[], picked: string[]): Option[] {
    const known = new Set(options.map((option) => option.key));

    return [...options, ...picked.filter((key) => !known.has(key)).map((key) => ({ key, label: filterKeyLabel(key), count: 0 }))];
}

function toggled(picked: string[], key: string): string[] {
    return picked.includes(key) ? picked.filter((item) => item !== key) : [...picked, key];
}

function Group({ title, options, picked, onChange, testIdPrefix }: { title: string; options: Option[]; picked: string[]; onChange: (next: string[]) => void; testIdPrefix: string }) {
    return (
        <div role="group" aria-label={title} className="py-1">
            <div className="px-2 pb-1 text-[11px] font-medium uppercase tracking-wide text-faint">{title}</div>
            {options.map((option) => (
                <div key={option.key} className="flex items-center gap-2 rounded-control px-2 py-1 hover:bg-panel-2">
                    <Checkbox
                        checked={picked.includes(option.key)}
                        onCheckedChange={() => onChange(toggled(picked, option.key))}
                        label={option.label}
                        className="min-w-0 flex-1"
                        data-testid={`${testIdPrefix}-${option.key}`}
                    />
                    <span className="text-[12px] tabular-nums text-muted">{option.count}</span>
                </div>
            ))}
        </div>
    );
}

export function ThreadFilterMenu({
    facets,
    sources,
    starters,
    onSourcesChange,
    onStartersChange,
    viewerStarterKey,
}: {
    facets: ThreadListFacets | null;
    sources: string[];
    starters: string[];
    onSourcesChange: (next: string[]) => void;
    onStartersChange: (next: string[]) => void;
    viewerStarterKey: string | null;
}) {
    const sourceOptions = withPicked(facets?.sources ?? [], sources);
    const starterOptions = withPicked(
        (facets?.starters ?? []).map((starter) => ({ key: starter.key, label: starter.name, count: starter.count })),
        starters,
    ).map((option) => ({
        ...option,
        label: option.key === viewerStarterKey ? 'You' : option.key === 'yak' ? 'Yak (no person)' : option.label,
    }));
    const pickedCount = sources.length + starters.length;

    return (
        <Popover.Root>
            <Popover.Trigger
                render={<Button variant="secondary" icon={<ListFilter size={13} />} className="shrink-0 px-2" data-testid="thread-filter-menu-button" />}
            >
                Filter
                {pickedCount > 0 ? <span className="ml-1 rounded-pill bg-panel-2 px-1.5 text-[11px] tabular-nums">{pickedCount}</span> : null}
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Positioner align="end" sideOffset={4} className="z-50 outline-none">
                    <Popover.Popup className="ui-floating max-h-[70vh] w-[260px] max-w-[calc(100vw-24px)] overflow-y-auto rounded-card border border-hair bg-panel p-1 shadow-overlay outline-none" data-testid="thread-filter-menu">
                        <Group title="Source" options={sourceOptions} picked={sources} onChange={onSourcesChange} testIdPrefix="thread-filter-source" />
                        <Group title="Started by" options={starterOptions} picked={starters} onChange={onStartersChange} testIdPrefix="thread-filter-starter" />
                    </Popover.Popup>
                </Popover.Positioner>
            </Popover.Portal>
        </Popover.Root>
    );
}
