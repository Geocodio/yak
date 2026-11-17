import { Tooltip } from '@geocodio/console-ui';
import { Info } from 'lucide-react';

/**
 * Field help for compact fields: an icon with a tooltip from `md` up, and
 * the same text as a caption under the field below it, where there is no
 * hover.
 */
export function InfoTip({ label }: { label: string }) {
    return (
        <>
            <Tooltip label={label}>
                <Info size={12} className="shrink-0 text-faint hover:text-muted max-md:hidden" aria-label={label} />
            </Tooltip>
            <p className="basis-full text-[11px] leading-snug text-faint md:hidden">{label}</p>
        </>
    );
}
