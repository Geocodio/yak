import { FileSpreadsheet, FileText, File as FileIcon } from 'lucide-react';
import type { SharedFile } from '@/types/threads';

/** A small preview for an image, a type icon for everything else. */
export function SharedFileThumb({ file, size = 'card' }: { file: SharedFile; size?: 'card' | 'rail' }) {
    const box = size === 'card' ? 'h-10 w-14' : 'size-7';

    if (file.kind === 'image') {
        return <img src={file.url} alt="" loading="lazy" className={`${box} shrink-0 rounded-[6px] border border-hair object-cover`} data-testid="shared-file-thumb" />;
    }

    const Icon = file.kind === 'csv' ? FileSpreadsheet : file.kind === 'file' ? FileIcon : FileText;

    return (
        <span className={`${size === 'card' ? 'size-9' : 'size-7'} flex shrink-0 items-center justify-center rounded-[8px] bg-panel-2 text-muted`} data-testid="shared-file-icon">
            <Icon size={size === 'card' ? 18 : 15} aria-hidden />
        </span>
    );
}
