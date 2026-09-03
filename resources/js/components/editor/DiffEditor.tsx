import { MergeView } from '@codemirror/merge';
import { EditorView } from '@codemirror/view';
import { useEffect, useRef } from 'react';
import { bladeOverlay, bladeTheme } from './bladeTheme';

/** Read-only side-by-side diff of the shipped default against the current draft. */
export function DiffEditor({ before, after, 'data-testid': dataTestId }: { before: string; after: string; 'data-testid'?: string }) {
    const host = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const el = host.current!;
        const merge = new MergeView({
            parent: el,
            a: {
                doc: before,
                extensions: [bladeTheme, bladeOverlay(), EditorView.lineWrapping, EditorView.editable.of(false), EditorView.contentAttributes.of({ 'aria-label': 'Shipped default' })],
            },
            b: {
                doc: after,
                extensions: [bladeTheme, bladeOverlay(), EditorView.lineWrapping, EditorView.editable.of(false), EditorView.contentAttributes.of({ 'aria-label': 'Current draft' })],
            },
        });

        return () => merge.destroy();
    }, [before, after]);

    return <div ref={host} className="h-full min-h-0 [&_.cm-editor]:h-full" data-testid={dataTestId} />;
}
