import { FileSpreadsheet, FileText, File as FileIcon } from 'lucide-react';
import { OpenFileButton } from '@/components/threads/files/SharedFileActions';
import type { QuestionAttachmentData } from '@/types/tasks';

const OPEN_BUTTON = 'border-hair bg-panel text-body hover:bg-panel-2 hover:opacity-100';

/**
 * A shared file Claude attached to a question, at reading size: an image as itself, an HTML page in a sandboxed
 * frame that may run its own scripts but never as Yak, anything else as a file tile. The caption opens it full size.
 */
export function QuestionAttachment({ attachment, size }: { attachment: QuestionAttachmentData; size: 'context' | 'option' }) {
    const height = size === 'context' ? 'h-[320px]' : 'h-[280px]';

    return (
        <figure className="m-0 flex min-w-0 flex-col overflow-hidden rounded-[10px] border border-hair bg-panel" data-testid="question-attachment" data-kind={attachment.kind}>
            {attachment.kind === 'image' ? (
                <div className={`flex ${height} items-center justify-center bg-panel-2 p-3`}>
                    <img src={attachment.url} alt={attachment.alt} loading="lazy" className="max-h-full max-w-full rounded-[6px] object-contain" />
                </div>
            ) : attachment.kind === 'report' ? (
                <iframe src={attachment.rawUrl} sandbox="allow-scripts" title={attachment.alt} loading="lazy" className={`block w-full ${height} border-0 bg-white`} />
            ) : (
                <div className="flex h-24 items-center justify-center gap-3 bg-panel-2 text-muted">
                    <FileTypeIcon kind={attachment.kind} />
                    <span className="truncate text-[13px] text-body">{attachment.name}</span>
                </div>
            )}
            <figcaption className="flex items-center justify-between gap-3 border-t border-hair px-3 py-2">
                <span className="min-w-0 truncate text-[12.5px] font-medium text-body">{attachment.alt}</span>
                <OpenFileButton file={attachment} className={OPEN_BUTTON} />
            </figcaption>
        </figure>
    );
}

function FileTypeIcon({ kind }: { kind: QuestionAttachmentData['kind'] }) {
    const Icon = kind === 'csv' ? FileSpreadsheet : kind === 'file' ? FileIcon : FileText;

    return <Icon size={20} aria-hidden />;
}
