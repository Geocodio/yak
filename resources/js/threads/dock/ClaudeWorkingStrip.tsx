import { MessageSquare } from 'lucide-react';
import type { ThreadData } from '@/types/threads';

/** Slim strip on the phone Preview and Terminal tabs while the agent runs; the button jumps back to Chat. */
export function ClaudeWorkingStrip({ thread, activity = null, onOpenChat }: { thread: ThreadData; activity?: string | null; onOpenChat: () => void }) {
    if (thread.runnerState !== 'busy') {
        return null;
    }

    return (
        <div className="flex shrink-0 items-center justify-between gap-3 border-b border-hair bg-panel-2 px-4 py-2 lg:hidden" data-testid="claude-working-strip">
            <span className="flex items-center gap-2 text-[12.5px] text-body">
                <span className="h-2 w-2 shrink-0 animate-pulse rounded-pill bg-info" aria-hidden="true" />
                Claude is working{activity !== null && activity !== '' ? `. ${activity}` : ''}
            </span>
            <button type="button" onClick={onOpenChat} className="inline-flex cursor-pointer items-center gap-1 text-[12.5px] text-accent-text hover:underline">
                <MessageSquare size={12} />
                Chat
            </button>
        </div>
    );
}
