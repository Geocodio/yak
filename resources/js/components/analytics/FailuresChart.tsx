import { HorizontalBars } from '@/components/analytics/HorizontalBars';
import { Empty, Section } from '@/components/analytics/Section';
import type { FailureRow } from '@/types/analytics';

export function FailuresChart({ failures }: { failures: FailureRow[] }) {
    return (
        <Section title="Failures" hint="Why agent runs ended in an error, by category. Counted per run, not per task.">
            {failures.length > 0 ? (
                <HorizontalBars rows={failures.map((row) => ({ label: row.category, value: row.count }))} valueLabel="Runs" />
            ) : (
                <Empty>No failed runs in this period.</Empty>
            )}
        </Section>
    );
}
