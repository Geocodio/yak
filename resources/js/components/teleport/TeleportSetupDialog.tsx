import { Button, Dialog } from '@geocodio/console-ui';
import { useDocsUrl } from '@/lib/docsUrl';
import { TeleportSetup } from './TeleportSetup';

export function TeleportSetupDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
    const docsUrl = useDocsUrl('teleport/');

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title="Set up Teleport"
            description="Teleport moves a thread between Yak and Claude Code on your computer, in both directions. Only committed work travels, through GitHub."
            width="w-[min(560px,100vw)]"
            data-testid="teleport-setup-dialog"
            footer={
                <div className="flex items-center gap-3">
                    <a href={docsUrl} target="_blank" rel="noreferrer" className="mr-auto rounded-[3px] text-[12.5px] text-accent-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">How Teleport works</a>
                    <Button variant="secondary" onClick={onClose} data-testid="teleport-setup-close">Close</Button>
                </div>
            }
        >
            <TeleportSetup />
        </Dialog>
    );
}
