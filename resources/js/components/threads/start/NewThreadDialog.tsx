import { Dialog } from '@geocodio/console-ui';
import type { NewThreadPageData } from '@/types/threads';
import { StartThreadPanel } from './StartThreadPanel';

export function NewThreadDialog({ open, onOpenChange, ...data }: Omit<NewThreadPageData, 'emailToYakAddress'> & { open: boolean; onOpenChange: (open: boolean) => void }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange} title="New thread" hideTitle width="w-[min(660px,100vw)]" className="rounded-xl p-3" data-testid="new-thread-dialog">
            <StartThreadPanel {...data} reloadOnly={['newThread']} autoFocus onStarted={() => onOpenChange(false)} onDismiss={() => onOpenChange(false)} />
        </Dialog>
    );
}
