import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { completionKeymap } from '@codemirror/autocomplete';
import { EditorState } from '@codemirror/state';
import { EditorView, highlightActiveLine, keymap, lineNumbers } from '@codemirror/view';
import { useEffect, useRef } from 'react';
import { bladeOverlay, bladeTheme, variableAutocomplete } from './bladeTheme';

export function CodeEditor({
    value,
    onChange,
    variables,
    onSave,
    ariaLabel = 'Prompt editor',
    'data-testid': dataTestId,
}: {
    value: string;
    onChange: (value: string) => void;
    variables: string[];
    onSave?: () => void;
    ariaLabel?: string;
    'data-testid'?: string;
}) {
    const host = useRef<HTMLDivElement>(null);
    const viewRef = useRef<EditorView | null>(null);
    const variablesRef = useRef(variables);
    const onChangeRef = useRef(onChange);
    const onSaveRef = useRef(onSave);

    variablesRef.current = variables;
    onChangeRef.current = onChange;
    onSaveRef.current = onSave;

    useEffect(() => {
        const el = host.current!;

        const updateListener = EditorView.updateListener.of((update) => {
            if (update.docChanged) {
                onChangeRef.current(update.state.doc.toString());
            }
        });

        const saveKeymap = keymap.of([
            {
                key: 'Mod-s',
                run: () => {
                    onSaveRef.current?.();
                    return true;
                },
            },
        ]);

        const state = EditorState.create({
            doc: value,
            extensions: [
                history(),
                lineNumbers(),
                highlightActiveLine(),
                saveKeymap,
                keymap.of([...defaultKeymap, ...historyKeymap, ...completionKeymap, indentWithTab]),
                bladeOverlay(),
                variableAutocomplete(variablesRef),
                bladeTheme,
                EditorView.lineWrapping,
                EditorView.contentAttributes.of({ 'aria-label': ariaLabel }),
                updateListener,
            ],
        });

        const view = new EditorView({ state, parent: el });
        viewRef.current = view;

        return () => {
            view.destroy();
            viewRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        const view = viewRef.current;
        if (view && value !== view.state.doc.toString()) {
            view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value } });
        }
    }, [value]);

    return <div ref={host} className="h-full min-h-0 [&_.cm-editor]:h-full" data-testid={dataTestId} />;
}
