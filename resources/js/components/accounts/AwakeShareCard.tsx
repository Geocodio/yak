import { Card } from './AccountsSection';

export type AwakeShareRow = { name: string; awake: number; cap: number };

/** Read-only per person count of running sandboxes against the cap. Admins only. */
export function AwakeShareCard({ rows }: { rows: AwakeShareRow[] }) {
    return (
        <Card className="overflow-hidden" data-testid="awake-share">
            <table className="w-full text-[13px]">
                <thead>
                    <tr className="border-b border-hair text-left text-[12px] text-muted">
                        <th className="px-4 py-2 font-medium">Person</th>
                        <th className="px-4 py-2 text-right font-medium">Awake sandboxes</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <tr><td colSpan={2} className="px-4 py-3 text-muted">No sandbox is awake right now.</td></tr>
                    )}
                    {rows.map((row) => (
                        <tr key={row.name} className="border-b border-hair last:border-0">
                            <td className="px-4 py-2">{row.name}</td>
                            <td className="tnum px-4 py-2 text-right">{row.awake} of {row.cap}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </Card>
    );
}
