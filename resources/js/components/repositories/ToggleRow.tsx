import { Toggle } from '@geocodio/console-ui';

export function ToggleRow({
    label,
    description,
    checked,
    onChange,
    id,
}: {
    label: string;
    description: string;
    checked: boolean;
    onChange: (value: boolean) => void;
    id?: string;
}) {
    return (
        <div id={id} className="flex items-start justify-between gap-6 rounded-card border border-hair bg-panel px-4 py-3 shadow-card">
            <div>
                <div className="text-[13px] font-medium">{label}</div>
                <div className="mt-0.5 text-[12px] text-muted">{description}</div>
            </div>
            <Toggle checked={checked} onCheckedChange={onChange} label={label} className="mt-0.5" />
        </div>
    );
}
