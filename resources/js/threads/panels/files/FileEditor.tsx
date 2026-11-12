import { RangeSet, StateField, type Extension } from '@codemirror/state';
import { EditorState } from '@codemirror/state';
import { EditorView, GutterMarker, gutter } from '@codemirror/view';
import { useEffect, useMemo, useState } from 'react';
import { CodeEditor } from '@/components/editor/CodeEditor';
import { detectLanguage } from '@/components/editor/languageForFile';
import { themeHighlighting } from '@/components/editor/themeHighlighting';
import type { OpenFileData } from '@/types/thread-files';
import { changedLines } from './changedLines';
import { languageLabel } from './languageLabel';

class ChangedMarker extends GutterMarker {
    toDOM(): HTMLElement {
        const element = document.createElement('div');
        element.style.cssText = 'width:3px;height:100%;background:var(--color-ok)';
        element.dataset.testid = 'file-changed-line';

        return element;
    }
}

const marker = new ChangedMarker();

function changedLineGutter(headContent: string | null): Extension {
    const field = StateField.define<RangeSet<GutterMarker>>({
        create: (state) => markersFor(headContent, state),
        update: (value, transaction) => (transaction.docChanged ? markersFor(headContent, transaction.state) : value),
    });

    return [field, gutter({ class: 'cm-changed-gutter', markers: (view) => view.state.field(field), initialSpacer: () => marker })];
}

function markersFor(headContent: string | null, state: EditorState): RangeSet<GutterMarker> {
    const ranges = changedLines(headContent, state.doc.toString())
        .filter((line) => line <= state.doc.lines)
        .map((line) => marker.range(state.doc.line(line).from));

    return RangeSet.of(ranges, true);
}

export type FileEditorProps = {
    file: OpenFileData;
    draft: string;
    canEdit: boolean;
    onDraftChange: (content: string) => void;
    onSave: () => void;
};

/** CodeMirror editor for one file. Mounted lazily so CodeMirror stays out of the main bundle. */
export default function FileEditor({ file, draft, canEdit, onDraftChange, onSave }: FileEditorProps) {
    const [language, setLanguage] = useState<Extension[] | null>(null);
    const isDirty = draft !== file.content;
    const [cursor, setCursor] = useState({ line: 1, column: 1 });

    useEffect(() => {
        let cancelled = false;
        const detected = detectLanguage(file.path.split('/').pop() ?? file.path);
        if (!detected) {
            setLanguage([]);
            return;
        }
        detected
            .load()
            .then((loaded) => !cancelled && setLanguage([loaded]))
            .catch(() => !cancelled && setLanguage([]));

        return () => {
            cancelled = true;
        };
    }, [file.path]);

    const extensions = useMemo(
        () => (language === null ? null : [
                ...language,
                themeHighlighting,
                EditorView.updateListener.of((update) => {
                    if (update.selectionSet || update.docChanged) {
                        const head = update.state.selection.main.head;
                        const line = update.state.doc.lineAt(head);
                        setCursor({ line: line.number, column: head - line.from + 1 });
                    }
                }), changedLineGutter(file.headContent), EditorState.readOnly.of(!canEdit)]),
        [language, file.headContent, canEdit],
    );

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-testid="file-editor">
            <div className="min-h-0 flex-1">
                {extensions !== null && (
                    <CodeEditor
                        key={`${file.path}:${file.baseSha256}`}
                        value={draft}
                        onChange={onDraftChange}
                        onSave={canEdit ? onSave : undefined}
                        ariaLabel={`Editing ${file.path}`}
                        languageExtensions={extensions as Extension[]}
                    />
                )}
            </div>
            <div className="flex items-center gap-4 border-t border-hair px-4 py-1.5 text-[12px] text-muted" data-testid="files-status-bar">
                <span data-testid="file-cursor">
                    Ln {cursor.line}, Col {cursor.column}
                </span>
                <span>UTF-8</span>
                <span data-testid="file-language">{languageLabel(file.path)}</span>
                <span className="ml-auto" data-testid="file-save-state">
                    {isDirty ? 'Unsaved' : 'Saved'}
                </span>
            </div>
        </div>
    );
}
