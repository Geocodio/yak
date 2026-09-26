import { Link } from '@inertiajs/react';
import { Badge, cn, Menu, StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, Th, Tr } from '@geocodio/console-ui';
import { ChevronDown } from 'lucide-react';
import { Empty, Section, TableScroll } from '@/components/analytics/Section';
import { formatCount, formatMs } from '@/lib/format';
import type { EventExplorer as EventExplorerData } from '@/types/analytics';

const CHIP_LIMIT = 6;
const JSON_LIMIT = 120;

function isScalar(value: unknown): value is string | number | boolean | null {
    return value === null || ['string', 'number', 'boolean'].includes(typeof value);
}

function Properties({ properties }: { properties: Record<string, unknown> | null }) {
    if (!properties || Object.keys(properties).length === 0) {
        return <span className="text-faint">–</span>;
    }

    const entries = Object.entries(properties);
    const json = JSON.stringify(properties);

    if (entries.length <= CHIP_LIMIT && entries.every(([, value]) => isScalar(value))) {
        return (
            <span className="flex flex-wrap gap-1" title={json}>
                {entries.map(([key, value]) => (
                    <span key={key} className="rounded-chip bg-panel-2 px-1.5 py-0.5 font-mono text-[11px] text-muted">
                        {key}=<span className="text-body">{String(value)}</span>
                    </span>
                ))}
            </span>
        );
    }

    return (
        <code className="font-mono text-[11px] text-muted" title={json}>
            {json.length > JSON_LIMIT ? `${json.slice(0, JSON_LIMIT)}…` : json}
        </code>
    );
}

export function EventExplorer({ explorer, selected, onSelect }: { explorer: EventExplorerData; selected: string; onSelect: (name: string) => void }) {
    return (
        <Section
            title="Event explorer"
            hint="The newest raw telemetry events, one row each. Filter by name to follow a single signal."
            action={
                <Menu
                    trigger={
                        <span className="flex items-center gap-1.5 text-[12px]">
                            <span className={selected ? 'text-body' : 'text-muted'}>{selected || 'All events'}</span>
                            <ChevronDown size={12} className="text-faint" />
                        </span>
                    }
                    className={cn('h-7 rounded-pill px-2.5', selected && 'border-accent/40 bg-accent-soft')}
                    items={[
                        { key: '__all__', label: 'All events', checked: selected === '', onSelect: () => onSelect('') },
                        ...explorer.names.map((name) => ({ key: name, label: name, checked: name === selected, onSelect: () => onSelect(name) })),
                    ]}
                />
            }
        >
            {explorer.rows.length > 0 ? (
                <TableScroll>
                    <StackedTable className="w-full">
                        <StackedThead>
                            <Tr>
                                <Th className="pl-4">When</Th>
                                <Th>Name</Th>
                                <Th>Repo</Th>
                                <Th>Source</Th>
                                <Th>Task</Th>
                                <Th className="md:text-right">Duration</Th>
                                <Th className="md:text-right">Value</Th>
                                <Th className="pr-4">Properties</Th>
                            </Tr>
                        </StackedThead>
                        <StackedTbody>
                            {explorer.rows.map((row) => (
                                <StackedTr key={row.id}>
                                    <StackedTd label="When" className="tnum pl-4 md:whitespace-nowrap text-muted">
                                        {new Date(row.occurredAt).toLocaleString()}
                                    </StackedTd>
                                    <StackedTd label="Name">
                                        <Badge tone="neutral">{row.name}</Badge>
                                    </StackedTd>
                                    <StackedTd label="Repo" className="font-mono text-[12px] text-muted">
                                        {row.repo ?? '–'}
                                    </StackedTd>
                                    <StackedTd label="Source" className="text-muted">
                                        {row.source ?? '–'}
                                    </StackedTd>
                                    <StackedTd label="Task" className="md:whitespace-nowrap">
                                        {row.taskUrl && row.taskId !== null ? (
                                            <Link href={row.taskUrl} className="text-[12px] text-accent">
                                                #{row.taskId}
                                            </Link>
                                        ) : (
                                            <span className="text-faint">–</span>
                                        )}
                                    </StackedTd>
                                    <StackedTd label="Duration" className="tnum md:text-right text-muted">
                                        {formatMs(row.durationMs)}
                                    </StackedTd>
                                    <StackedTd label="Value" className="tnum md:text-right text-muted">
                                        {row.value === null ? '–' : formatCount(row.value)}
                                    </StackedTd>
                                    <StackedTd label="Properties" className="pr-4">
                                        <Properties properties={row.properties} />
                                    </StackedTd>
                                </StackedTr>
                            ))}
                        </StackedTbody>
                    </StackedTable>
                </TableScroll>
            ) : (
                <Empty>{selected ? `No ${selected} events in this period.` : 'No events in this period.'}</Empty>
            )}
        </Section>
    );
}
