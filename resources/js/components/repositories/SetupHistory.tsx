import { router } from '@inertiajs/react';
import { Button, StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, StatusPill, Th, Tr } from '@geocodio/console-ui';
import { STATUS, type TaskStatus } from '@/lib/status';
import type { SetupHistoryRow } from '@/types/repositories';

export function SetupHistory({ rows, viewAllHref }: { rows: SetupHistoryRow[]; viewAllHref: string | null }) {
    if (rows.length === 0) {
        return <p className="text-[13px] text-muted">No setup runs yet.</p>;
    }

    return (
        <div className="flex flex-col gap-3">
            {viewAllHref && (
                <div className="flex justify-end">
                    <Button variant="link" className="text-[12px]" onClick={() => router.visit(viewAllHref)}>
                        View all
                    </Button>
                </div>
            )}
            <div className="md:overflow-x-auto rounded-card border border-hair bg-panel shadow-card">
                <StackedTable className="w-full">
                    <StackedThead>
                        <Tr>
                            <Th>Status</Th>
                            <Th>ID</Th>
                            <Th>Started</Th>
                            <Th className="text-right">Duration</Th>
                        </Tr>
                    </StackedThead>
                    <StackedTbody>
                        {rows.map((row) => {
                            const status = STATUS[row.status as TaskStatus];
                            return (
                                <StackedTr key={row.id}>
                                    <StackedTd label="Status">
                                        <StatusPill tone={status?.tone ?? 'idle'} label={status?.label ?? row.status} pulse={status?.live} />
                                    </StackedTd>
                                    <StackedTd label="ID" className="font-mono text-[12px]">
                                        {row.id}
                                    </StackedTd>
                                    <StackedTd label="Started" className="text-muted">
                                        {row.startedAgo}
                                    </StackedTd>
                                    <StackedTd label="Duration" className="tnum md:text-right text-muted">
                                        {row.duration}
                                    </StackedTd>
                                </StackedTr>
                            );
                        })}
                    </StackedTbody>
                </StackedTable>
            </div>
        </div>
    );
}
