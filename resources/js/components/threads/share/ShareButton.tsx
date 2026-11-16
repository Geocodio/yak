import { Button } from '@geocodio/console-ui';
import { Share2 } from 'lucide-react';
import { useState } from 'react';
import type { ThreadData } from '@/types/threads';
import { ShareThreadDialog } from './ShareThreadDialog';

export function ShareButton({ thread }: { thread: ThreadData }) {
    const [isOpen, setIsOpen] = useState(false);

    if (!thread.isOwner) {
        return null;
    }

    return (
        <>
            <Button variant="secondary" icon={<Share2 size={14} />} onClick={() => setIsOpen(true)} data-testid="share-button">Share</Button>
            {isOpen && <ShareThreadDialog thread={thread} onClose={() => setIsOpen(false)} />}
        </>
    );
}
