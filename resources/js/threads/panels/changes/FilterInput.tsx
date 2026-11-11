import { TextInput } from '@geocodio/console-ui';
import { forwardRef } from 'react';

type Props = { value: string; onChange: (value: string) => void };

export const FilterInput = forwardRef<HTMLInputElement, Props>(function FilterInput({ value, onChange }, ref) {
    return (
        <div className="relative min-w-0 flex-1">
            <TextInput ref={ref} value={value} onChange={(event) => onChange(event.target.value)} placeholder="Filter files" aria-label="Filter files" className="pr-8" />
            <kbd data-testid="changes-filter-hint" aria-hidden className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded-chip border border-hair px-1 font-mono text-[11px] text-muted">
                /
            </kbd>
        </div>
    );
});
