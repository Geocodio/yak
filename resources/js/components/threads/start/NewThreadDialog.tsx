import { Dialog } from '@geocodio/console-ui';
import type { NewThreadPageData } from '@/types/threads';
import { StartThreadPanel } from './StartThreadPanel';

export function NewThreadDialog({
    open,
    onOpenChange,
    initialPrompt,
    initialRepository,
    ...data
}: Omit<NewThreadPageData, 'emailToYakAddress'> & { open: boolean; onOpenChange: (open: boolean) => void; initialPrompt?: string; initialRepository?: string }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange} title="New thread" hideTitle width="w-[min(660px,100vw)]" className="overflow-hidden rounded-xl p-0" data-testid="new-thread-dialog">
            <StartThreadPanel {...data} defaultRepo={initialRepository ?? data.defaultRepo} initialDescription={initialPrompt} reloadOnly={['newThread']} autoFocus isEmbedded onStarted={() => onOpenChange(false)} onDismiss={() => onOpenChange(false)} />
        </Dialog>
    );
}
