import { router } from '@inertiajs/react';
import { Button, Dialog, Toggle, cn, toast } from '@geocodio/console-ui';
import { Check, Download, ExternalLink, Eye, Link as LinkIcon, Users } from 'lucide-react';
import { useState } from 'react';
import { MediaLightbox } from '@/components/tasks/MediaLightbox';
import { csrfToken } from '@/lib/csrfToken';
import { teamShare } from '@/routes/threads/shared-files';
import type { SharedFile } from '@/types/threads';
import { fileLink, openAction } from './sharedFiles';

const BUTTON = 'inline-flex min-h-[30px] cursor-pointer items-center gap-1.5 rounded-[8px] border border-hair bg-panel px-2.5 py-1 text-[12.5px] text-body no-underline hover:bg-panel-2';
const PRIMARY = 'inline-flex min-h-[30px] cursor-pointer items-center gap-1.5 rounded-[8px] border border-accent bg-accent px-2.5 py-1 text-[12.5px] text-accent-ink no-underline hover:opacity-90';

/** Open for a report (the viewer in a new tab), View for an image (the lightbox), Download for the rest. */
export function OpenFileButton({ file, className }: { file: SharedFile; className?: string }) {
    const [isViewing, setIsViewing] = useState(false);
    const action = openAction(file);

    if (action === 'view') {
        return (
            <>
                <button type="button" onClick={() => setIsViewing(true)} className={cn(PRIMARY, className)} data-testid="shared-file-open">
                    <Eye size={14} aria-hidden />
                    View
                </button>
                <MediaLightbox
                    media={isViewing ? [{ id: 0, kind: 'image', url: file.url, thumbUrl: null, caption: file.name, downloadUrl: file.url, fileName: file.name }] : null}
                    index={0}
                    onOpenChange={(open) => !open && setIsViewing(false)}
                    onIndexChange={() => undefined}
                />
            </>
        );
    }

    return (
        <a href={file.url} {...(action === 'open' ? { target: '_blank', rel: 'noopener noreferrer' } : { download: file.name })} className={cn(PRIMARY, className)} data-testid="shared-file-open">
            {action === 'open' ? <ExternalLink size={14} aria-hidden /> : <Download size={14} aria-hidden />}
            {action === 'open' ? 'Open' : 'Download'}
        </a>
    );
}

export function copyFileLink(file: SharedFile): void {
    navigator.clipboard.writeText(fileLink(file)).then(
        () => toast.success('Copied the link'),
        () => toast.error('Could not copy the link.'),
    );
}

export function CopyLinkButton({ file }: { file: SharedFile }) {
    const [isCopied, setIsCopied] = useState(false);

    return (
        <button
            type="button"
            onClick={() => {
                copyFileLink(file);
                setIsCopied(true);
                window.setTimeout(() => setIsCopied(false), 1800);
            }}
            className={cn(BUTTON, isCopied && 'border-transparent bg-ok-soft text-ok')}
            data-testid="shared-file-copy-link"
        >
            {isCopied ? <Check size={14} aria-hidden /> : <LinkIcon size={14} aria-hidden />}
            {isCopied ? 'Copied' : 'Copy link'}
        </button>
    );
}

/** The Team badge: a tint and a dot. */
export function TeamBadge() {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-pill bg-ok-soft px-2 py-0.5 text-[11.5px] text-ok" data-testid="shared-file-team-badge">
            <span className="size-[7px] rounded-pill bg-ok" aria-hidden />
            Team
        </span>
    );
}

/**
 * The share control of one file. Turning sharing on for a private thread asks first; on a team thread it applies at
 * once. The switch changes this file only, never the thread.
 */
export function ShareDialog({ threadId, file, isTeamThread, onClose }: { threadId: string; file: SharedFile; isTeamThread: boolean; onClose: () => void }) {
    const [isConfirming, setIsConfirming] = useState(false);
    const [isSaving, setIsSaving] = useState(false);

    const save = (shared: boolean) => {
        setIsSaving(true);
        fetch(teamShare.url({ thread: threadId, sharedFile: file.id }), {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ shared }),
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                setIsConfirming(false);
                router.reload({ only: ['sharedFiles'], onFinish: () => setIsSaving(false) });
            })
            .catch(() => {
                setIsSaving(false);
                toast.error('Could not change sharing. Nothing has changed.');
            });
    };

    const change = (checked: boolean) => (checked && !isTeamThread ? setIsConfirming(true) : save(checked));

    if (isConfirming) {
        return (
            <Dialog
                open
                onOpenChange={(open) => !open && !isSaving && setIsConfirming(false)}
                title="Share this file with the team?"
                data-testid="shared-file-confirm"
                footer={
                    <div className="flex items-center justify-end gap-2">
                        <Button variant="tertiary" disabled={isSaving} onClick={() => setIsConfirming(false)} data-testid="shared-file-confirm-cancel">Cancel</Button>
                        <Button variant="primary" pending={isSaving} onClick={() => save(true)} data-testid="shared-file-confirm-share">Share file</Button>
                    </div>
                }
            >
                <div className="flex flex-col gap-2 text-[13px]">
                    <p>This thread is private. Anyone signed in to Yak will be able to open {file.name}, but not the thread. Turn it off any time.</p>
                    <p className="text-[12.5px] text-muted">Check the file does not include anything from your computer you want to keep private.</p>
                </div>
            </Dialog>
        );
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()} title={file.name} width="w-[min(400px,100vw)]" data-testid="shared-file-share-dialog">
            <div className="flex flex-col gap-3">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <div className="text-[13.5px] font-medium text-body">Share with team</div>
                        <p className="text-[12.5px] text-muted">{file.sharedWithTeam ? 'Anyone signed in to Yak can open this file. The thread stays private.' : 'Only people who can read this thread can open the link.'}</p>
                    </div>
                    <Toggle checked={file.sharedWithTeam} onCheckedChange={change} label="Share with team" disabled={isSaving} data-testid="shared-file-team-toggle" />
                </div>
                <div className="flex items-center gap-2 rounded-[8px] bg-panel-2 py-1.5 pl-2.5 pr-1.5 text-[12px] text-muted">
                    <span className="min-w-0 flex-1 truncate" data-testid="shared-file-link">{fileLink(file)}</span>
                    <CopyLinkButton file={file} />
                </div>
            </div>
        </Dialog>
    );
}

/** The icon button that opens the share control; lit when the file is shared with the team. */
export function ShareButton({ file, canShare, onClick }: { file: SharedFile; canShare: boolean; onClick: () => void }) {
    if (!canShare) {
        return null;
    }

    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={file.sharedWithTeam ? 'Shared with the team. Change sharing' : 'Share with team'}
            title={file.sharedWithTeam ? 'Shared with the team' : 'Share with team'}
            className={cn('inline-flex size-[30px] cursor-pointer items-center justify-center rounded-[8px] border border-transparent hover:bg-panel-2', file.sharedWithTeam ? 'bg-ok-soft text-ok' : 'text-muted')}
            data-testid="shared-file-share"
        >
            <Users size={14} aria-hidden />
        </button>
    );
}
