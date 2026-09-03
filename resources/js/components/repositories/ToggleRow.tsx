import { Toggle } from '@geocodio/console-ui';
import { BookOpen } from 'lucide-react';

export function ToggleRow({
    label,
    description,
    checked,
    onChange,
    id,
    docsHref,
}: {
    label: string;
    description: string;
    checked: boolean;
    onChange: (value: boolean) => void;
    id?: string;
    docsHref?: string;
}) {
    return (
        <div id={id} className="flex items-start justify-between gap-6 rounded-card border border-hair bg-panel px-4 py-3 shadow-card">
            <div>
                <div className="text-[13px] font-medium">{label}</div>
                <div className="mt-0.5 text-[12px] text-muted">{description}</div>
                {docsHref && (
                    <a
                        href={docsHref}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mt-2 inline-flex items-center gap-1 text-[12px] text-accent-text hover:underline"
                    >
                        <BookOpen size={12} /> Docs
                    </a>
                )}
            </div>
            <Toggle checked={checked} onCheckedChange={onChange} label={label} className="mt-0.5" />
        </div>
    );
}
