import { Badge } from '@geocodio/console-ui';
import { useState } from 'react';
import type { ThreadData } from '@/types/threads';
import { ShareThreadDialog } from './ShareThreadDialog';
import { SharedPeopleList } from './SharedPeopleList';

/** The Environment panel's Visibility row, for the thread owner only. */
export function VisibilityRow({ thread }: { thread: ThreadData }) {
    const [isOpen, setIsOpen] = useState(false);

    if (!thread.isOwner) {
        return null;
    }

    const isPrivate = thread.visibility === 'private' && thread.sharedWithCount === 0;
    const isTeam = thread.visibility === 'team';
    const label = isPrivate ? 'Private' : isTeam ? 'Shared with the team' : `Shared with ${thread.sharedWithCount} ${thread.sharedWithCount === 1 ? 'person' : 'people'}`;
    const sentence = isPrivate ? 'Only you can read this thread.' : isTeam ? 'Everyone on the team can read this thread.' : 'The people below can read the conversation, the changes and the details.';

    return (
        <>
            <div className="flex items-start gap-3 border-b border-hair py-3" data-testid="environment-visibility">
                <dt className="flex w-24 shrink-0 items-center text-[12px] text-muted">Visibility</dt>
                <dd className="min-w-0 flex-1 text-[13px] text-body">
                    <span className="inline-flex items-center gap-2">
                        <Badge tone={isPrivate ? 'neutral' : 'accent'} data-testid="visibility-row-state">{label}</Badge>
                        <button type="button" onClick={() => setIsOpen(true)} className="cursor-pointer text-[12px] text-accent-text hover:underline" data-testid="visibility-row-share">
                            {isPrivate ? 'Share' : 'Change'}
                        </button>
                    </span>
                    <p className="mt-0.5 text-[12px] text-muted">{sentence}</p>
                    <SharedPeopleList thread={thread} />
                </dd>
            </div>
            {isOpen && <ShareThreadDialog thread={thread} onClose={() => setIsOpen(false)} />}
        </>
    );
}
