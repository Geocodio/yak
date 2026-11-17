import { Button } from '@geocodio/console-ui';
import { ScrollText } from 'lucide-react';

/** Opens the full log of one run. */
export function RunLogButton({ onOpen }: { onOpen: () => void }) {
    return (
        <Button variant="tertiary" className="h-7 shrink-0" icon={<ScrollText size={13} />} onClick={onOpen} data-testid="run-log-button">
            Full log
        </Button>
    );
}
