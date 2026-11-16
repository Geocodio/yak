import { useState } from 'react';
import { CopyLinkButton, OpenFileButton, ShareButton, ShareDialog, TeamBadge } from '@/components/threads/files/SharedFileActions';
import { SharedFileThumb } from '@/components/threads/files/SharedFileThumb';
import { kindLabel } from '@/components/threads/files/sharedFiles';
import { formatBytes } from '@/lib/format';
import type { SharedFile } from '@/types/threads';

function FileRow({ file, threadId, canShare, isTeamThread }: { file: SharedFile; threadId: string; canShare: boolean; isTeamThread: boolean }) {
    const [isSharing, setIsSharing] = useState(false);

    return (
        <div className="flex flex-wrap items-center gap-3 border-t border-hair px-3 py-2.5 first:border-t-0 max-[520px]:flex-col max-[520px]:items-stretch" data-testid="shared-file-row" data-file-name={file.name}>
            <div className="flex min-w-[140px] flex-1 items-center gap-3">
                <SharedFileThumb file={file} />
                <div className="min-w-0">
                    <div className="break-words text-[13.5px] font-medium text-body" data-testid="shared-file-name">{file.name}</div>
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] text-muted">
                        <span>{kindLabel(file)}</span>
                        <span>{formatBytes(file.sizeBytes)}</span>
                        {file.sharedWithTeam && <TeamBadge />}
                    </div>
                </div>
            </div>
            <div className="flex flex-wrap items-center justify-end gap-1 max-[520px]:justify-start">
                <OpenFileButton file={file} />
                <CopyLinkButton file={file} />
                <ShareButton file={file} canShare={canShare} onClick={() => setIsSharing(true)} />
            </div>
            {isSharing && <ShareDialog threadId={threadId} file={file} isTeamThread={isTeamThread} onClose={() => setIsSharing(false)} />}
        </div>
    );
}

/** The files one Claude reply produced, in the same gutter and weight as the walkthrough card. */
export function SharedFilesCard({ files, threadId, canShare, isTeamThread }: { files: SharedFile[]; threadId: string; canShare: boolean; isTeamThread: boolean }) {
    return (
        <div className="flex gap-3" data-testid="thread-shared-files">
            <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-pill bg-accent-soft text-[11px] font-medium text-accent-text">C</div>
            <div className="min-w-0 flex-1">
                <div className="text-[12px] font-medium text-body">Claude</div>
                <div className="mt-1 overflow-hidden rounded-[10px] border border-hair bg-panel">
                    <div className="flex items-center justify-between bg-panel-2 px-3 py-2 text-[12px] text-muted">
                        <span>{files.length === 1 ? 'Shared file' : `${files.length} shared files`}</span>
                        {files.length > 1 && <span>Newest last</span>}
                    </div>
                    {files.map((file) => (
                        <FileRow key={file.id} file={file} threadId={threadId} canShare={canShare} isTeamThread={isTeamThread} />
                    ))}
                </div>
            </div>
        </div>
    );
}
