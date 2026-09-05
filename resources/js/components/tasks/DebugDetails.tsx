import { ChevronRight } from 'lucide-react';
import type { DebugData } from '@/types/tasks';

export function DebugDetails({ debug }: { debug: DebugData }) {
    const entries = Object.entries(debug);
    if (entries.length === 0) {
        return null;
    }

    return (
        <details className="group" data-testid="debug-details">
            <summary className="flex cursor-pointer items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-faint">
                <ChevronRight size={11} className="transition-transform group-open:rotate-90" /> Debug details
            </summary>
            <dl className="mt-2 grid grid-cols-1 gap-x-3 gap-y-1 text-[11px] sm:grid-cols-[auto_1fr]">
                {entries.map(([key, value]) => (
                    <div key={key} className="contents">
                        <dt className="text-faint">{key}</dt>
                        <dd className={key === 'Error Log' ? 'min-w-0 break-all whitespace-pre-wrap font-mono text-muted' : 'min-w-0 break-all font-mono text-muted'}>{value}</dd>
                    </div>
                ))}
            </dl>
        </details>
    );
}
