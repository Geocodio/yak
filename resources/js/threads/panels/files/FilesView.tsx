import { cn } from '@geocodio/console-ui';
import { useRef } from 'react';
import { useMediaQuery } from '@/lib/useMediaQuery';
import type { PanelProps } from '@/threads/panels';
import { ChangesPanel } from '../changes/ChangesPanel';
import FilesPanel from './FilesPanel';
import { FilesModeSwitch } from './FilesModeSwitch';
import { resolveFilesMode, type FilesMode } from './filesMode';

/**
 * The Files view: one file tree with a Changed / Everything switch. Changed
 * shows the changed files and their diffs; Everything shows the whole
 * repository and sandbox with the editor. Each side mounts the first time it
 * is shown and then stays, so open editor tabs and diff state survive a switch.
 */
export default function FilesView(props: PanelProps) {
    const { diff = null, isDiffLoading = false, filesMode = null, onFilesModeChange } = props;
    const isNarrow = !useMediaQuery('(min-width: 1024px)');
    const mode = resolveFilesMode(filesMode, diff, isDiffLoading);
    const shown = useRef(new Set<FilesMode>());

    if (mode === null) {
        return (
            <div className="flex min-h-0 flex-1 flex-col p-4 text-[13px] text-muted" data-testid="files-view-loading">
                Loading files
            </div>
        );
    }

    shown.current.add(mode);
    const modeSwitch = <FilesModeSwitch mode={mode} changedCount={diff?.filesCount ?? 0} onChange={(next) => onFilesModeChange?.(next)} />;
    const treeSwitch = isNarrow ? undefined : modeSwitch;

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-testid="files-view" data-mode={mode}>
            {isNarrow && <div className="shrink-0 border-b border-hair px-4 py-2">{modeSwitch}</div>}
            {shown.current.has('changed') && (
                <div className={cn('min-h-0 flex-1 flex-col', mode === 'changed' ? 'flex' : 'hidden')}>
                    <ChangesPanel {...props} modeSwitch={mode === 'changed' ? treeSwitch : undefined} />
                </div>
            )}
            {shown.current.has('everything') && (
                <div className={cn('min-h-0 flex-1', mode === 'everything' ? 'flex' : 'hidden')}>
                    <FilesPanel {...props} modeSwitch={mode === 'everything' ? treeSwitch : undefined} />
                </div>
            )}
        </div>
    );
}
