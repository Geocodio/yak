import { Menu } from '@geocodio/console-ui';
import { MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import { copyFileLink, OpenFileButton, ShareDialog, TeamBadge } from '@/components/threads/files/SharedFileActions';
import { SharedFileThumb } from '@/components/threads/files/SharedFileThumb';
import { formatAgo, formatBytes } from '@/lib/format';
import type { PanelProps } from '@/threads/panels';
import type { SharedFile } from '@/types/threads';
import { OverviewGroup } from '../environment/OverviewRow';

function Row({ file, threadId, canShare, isTeamThread }: { file: SharedFile; threadId: string; canShare: boolean; isTeamThread: boolean }) {
    const [isSharing, setIsSharing] = useState(false);

    return (
        <li className="flex items-center gap-2.5 rounded-[8px] px-1.5 py-2 hover:bg-panel-2" data-testid="rail-shared-file" data-file-name={file.name}>
            <SharedFileThumb file={file} size="rail" />
            <div className="min-w-0 flex-1">
                <div className="truncate text-[13px] text-body" data-testid="rail-shared-file-name">{file.name}</div>
                <div className="flex items-center gap-1.5 text-[12px] text-muted">
                    <span>{formatBytes(file.sizeBytes)} · {formatAgo(file.createdAt)}</span>
                    {file.sharedWithTeam && <TeamBadge />}
                </div>
            </div>
            <OpenFileButton file={file} className="!min-h-[26px] !px-2" />
            <Menu
                trigger={<MoreHorizontal size={14} aria-hidden />}
                aria-label={`More for ${file.name}`}
                align="end"
                data-testid="rail-shared-file-menu"
                items={[
                    { key: 'copy', label: 'Copy link', testId: 'rail-shared-file-copy', onSelect: () => copyFileLink(file) },
                    ...(canShare ? [{ key: 'share', label: 'Share with team', testId: 'rail-shared-file-share', onSelect: () => setIsSharing(true) }] : []),
                ]}
            />
            {isSharing && <ShareDialog threadId={threadId} file={file} isTeamThread={isTeamThread} onClose={() => setIsSharing(false)} />}
        </li>
    );
}

/** Every file Claude made for the person on this thread, newest first. Hidden when there are none. */
export function SharedFilesGroup({ thread, canDrive, sharedFiles = [] }: PanelProps) {
    if (sharedFiles.length === 0) {
        return null;
    }

    return (
        <OverviewGroup title="Files" aside={<span className="ml-auto font-normal" data-testid="rail-shared-files-count">{sharedFiles.length}</span>} testId="overview-shared-files">
            <ul className="m-0 flex list-none flex-col p-0">
                {[...sharedFiles].reverse().map((file) => (
                    <Row key={file.id} file={file} threadId={thread.id} canShare={canDrive} isTeamThread={thread.visibility === 'team'} />
                ))}
            </ul>
        </OverviewGroup>
    );
}
