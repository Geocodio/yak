import { Button } from '@geocodio/console-ui';
import { ScrollText } from 'lucide-react';

/** Opens the full log of one run. */
export function RunLogButton({ onOpen, testId = 'run-log-button' }: { onOpen: () => void; testId?: string }) {
    return (
        <Button variant="tertiary" className="h-7 shrink-0" icon={<ScrollText size={13} />} onClick={onOpen} data-testid={testId}>
            Full log
        </Button>
    );
}
