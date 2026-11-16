import { Badge } from '@geocodio/console-ui';
import { useState } from 'react';
import { OverviewRow, RowNote } from '@/threads/panels/environment/OverviewRow';
import type { ThreadData } from '@/types/threads';
import { ShareThreadDialog } from './ShareThreadDialog';
import { SharedPeopleList } from './SharedPeopleList';

/** The Overview's Visibility row, for the thread owner only. */
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
            <OverviewRow label="Visibility" testId="environment-visibility">
                <Badge tone={isPrivate ? 'neutral' : 'accent'} data-testid="visibility-row-state">{label}</Badge>
                <button type="button" onClick={() => setIsOpen(true)} className="ml-auto cursor-pointer text-[12.5px] text-accent-text hover:underline" data-testid="visibility-row-share">
                    {isPrivate ? 'Share' : 'Change'}
                </button>
                <RowNote>{sentence}</RowNote>
                <div className="basis-full">
                    <SharedPeopleList thread={thread} />
                </div>
            </OverviewRow>
            {isOpen && <ShareThreadDialog thread={thread} onClose={() => setIsOpen(false)} />}
        </>
    );
}
