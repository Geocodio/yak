import { Button, StatusPill, Table, Tbody, Td, Th, Thead, Tr } from '@geocodio/console-ui';
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
                    <Button variant="link" className="text-[12px]" onClick={() => (window.location.href = viewAllHref)}>
                        View all
                    </Button>
                </div>
            )}
            <div className="overflow-hidden rounded-card border border-hair bg-panel shadow-card">
                <Table className="w-full">
                    <Thead>
                        <Tr>
                            <Th>Status</Th>
                            <Th>ID</Th>
                            <Th>Started</Th>
                            <Th className="text-right">Duration</Th>
                        </Tr>
                    </Thead>
                    <Tbody>
                        {rows.map((row) => {
                            const status = STATUS[row.status as TaskStatus];
                            return (
                                <Tr key={row.id}>
                                    <Td>
                                        <StatusPill tone={status?.tone ?? 'idle'} label={status?.label ?? row.status} pulse={status?.live} />
                                    </Td>
                                    <Td className="font-mono text-[12px]">{row.id}</Td>
                                    <Td className="text-muted">{row.startedAgo}</Td>
                                    <Td className="tnum text-right text-muted">{row.duration}</Td>
                                </Tr>
                            );
                        })}
                    </Tbody>
                </Table>
            </div>
        </div>
    );
}
