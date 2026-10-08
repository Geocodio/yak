import { Compartment, EditorState } from '@codemirror/state';
import { EditorView, lineNumbers } from '@codemirror/view';
import { useEffect, useRef } from 'react';
import { bladeTheme } from './bladeTheme';
import { detectLanguage } from './languageForFile';
import { themeHighlighting } from './themeHighlighting';

/**
 * Read-only CodeMirror view of a file, highlighted for the language its
 * name suggests. The text can be selected, searched with the browser and
 * copied, but not edited. Plain text when the type isn't recognised.
 */
export function CodeViewer({ value, fileName, 'data-testid': dataTestId }: { value: string; fileName: string; 'data-testid'?: string }) {
    const host = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const language = new Compartment();
        const view = new EditorView({
            parent: host.current!,
            state: EditorState.create({
                doc: value,
                extensions: [
                    EditorState.readOnly.of(true),
                    lineNumbers(),
                    EditorView.lineWrapping,
                    bladeTheme,
                    themeHighlighting,
                    language.of([]),
                    EditorView.contentAttributes.of({ 'aria-label': `Contents of ${fileName}` }),
                ],
            }),
        });

        let cancelled = false;
        detectLanguage(fileName)
            ?.load()
            .then((loaded) => {
                if (!cancelled) {
                    view.dispatch({ effects: language.reconfigure(loaded) });
                }
            })
            // An unloadable mode leaves the file readable as plain text.
            .catch(() => undefined);

        return () => {
            cancelled = true;
            view.destroy();
        };
    }, [value, fileName]);

    return <div ref={host} className="h-full min-h-0 [&_.cm-editor]:h-full" data-testid={dataTestId} />;
}
