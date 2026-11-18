import { Tooltip } from '@geocodio/console-ui';
import { Info } from 'lucide-react';

export function InfoTip({ label }: { label: string }) {
    return (
        <Tooltip label={label}>
            <span tabIndex={0} className="inline-flex cursor-help text-muted">
                <Info size={12} aria-hidden />
                <span className="sr-only">{label}</span>
            </span>
        </Tooltip>
    );
}
