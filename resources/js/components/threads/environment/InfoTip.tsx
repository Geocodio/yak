import { Tooltip } from '@geocodio/console-ui';
import { Info } from 'lucide-react';

export function InfoTip({ label }: { label: string }) {
    return (
        <Tooltip label={label}>
            <span tabIndex={0} aria-label={label} className="inline-flex cursor-help text-muted">
                <Info size={12} />
            </span>
        </Tooltip>
    );
}
