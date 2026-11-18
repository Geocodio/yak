import { cn, Menu } from '@geocodio/console-ui';
import { ChevronDown } from 'lucide-react';

/**
 * A single filter's trigger and options, shared by the desktop filter row
 * and the phone filters sheet. `testId` marks the trigger for tests and
 * `block` widens it to a full-width row for the sheet.
 */
export function FilterMenu({
    label,
    value,
    options,
    onChange,
    testId,
    block,
}: {
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
    testId?: string;
    block?: boolean;
}) {
    const selected = options.find((o) => o.value === value);
    return (
        <Menu
            trigger={
                <span className="flex items-center gap-1.5 text-[12px]">
                    <span className={value ? 'text-body' : 'text-muted'}>{selected ? selected.label : label}</span>
                    <ChevronDown size={12} className="text-faint" />
                </span>
            }
            className={cn('h-7 rounded-pill px-2.5', value && 'border-accent/40 bg-accent-soft', block && 'h-10 w-full justify-between')}
            data-testid={testId}
            items={options.map((option) => ({
                key: option.value || '__all__',
                label: option.label,
                checked: option.value === value,
                onSelect: () => onChange(option.value),
            }))}
        />
    );
}
