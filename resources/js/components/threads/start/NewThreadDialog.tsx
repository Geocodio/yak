import { Dialog } from '@geocodio/console-ui';
import type { NewThreadPageData } from '@/types/threads';
import { StartThreadForm } from './StartThreadForm';

export function NewThreadDialog({ open, onOpenChange, ...data }: Omit<NewThreadPageData, 'emailToYakAddress'> & { open: boolean; onOpenChange: (open: boolean) => void }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange} title="New thread" hideTitle width="w-[min(660px,100vw)]" className="rounded-xl p-0" data-testid="new-thread-dialog">
            <StartThreadForm {...data} autoFocus onStarted={() => onOpenChange(false)} />
        </Dialog>
    );
}
