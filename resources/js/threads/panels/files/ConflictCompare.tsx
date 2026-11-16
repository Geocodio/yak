import { MergeView } from '@codemirror/merge';
import { EditorState } from '@codemirror/state';
import { EditorView } from '@codemirror/view';
import { useEffect, useRef } from 'react';
import { bladeTheme } from '@/components/editor/bladeTheme';

const readOnly = (label: string) => [bladeTheme, EditorView.lineWrapping, EditorState.readOnly.of(true), EditorView.editable.of(false), EditorView.contentAttributes.of({ 'aria-label': label })];

/** Your buffer next to Claude's version. Both sides are read only and there are no merge controls. */
export function ConflictCompare({ mine, theirs }: { mine: string; theirs: string }) {
    const host = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const merge = new MergeView({
            parent: host.current!,
            a: { doc: mine, extensions: readOnly('Your version') },
            b: { doc: theirs, extensions: readOnly("Claude's version") },
        });

        return () => merge.destroy();
    }, [mine, theirs]);

    return <div ref={host} className="min-h-0 flex-1 overflow-auto [&_.cm-editor]:h-full" data-testid="conflict-compare-view" />;
}
