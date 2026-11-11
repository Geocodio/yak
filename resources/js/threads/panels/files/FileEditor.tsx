import { RangeSet, StateField, type Extension } from '@codemirror/state';
import { EditorState } from '@codemirror/state';
import { GutterMarker, gutter } from '@codemirror/view';
import { useEffect, useMemo, useState } from 'react';
import { CodeEditor } from '@/components/editor/CodeEditor';
import { detectLanguage } from '@/components/editor/languageForFile';
import { themeHighlighting } from '@/components/editor/themeHighlighting';
import type { OpenFileData } from '@/types/thread-files';
import { changedLines } from './changedLines';

class ChangedMarker extends GutterMarker {
    toDOM(): HTMLElement {
        const element = document.createElement('div');
        element.style.cssText = 'width:3px;height:100%;background:var(--color-primary, #3b82f6)';
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
    isSaving: boolean;
    onDraftChange: (content: string) => void;
    onSave: () => void;
};

/** CodeMirror editor for one file. Mounted lazily so CodeMirror stays out of the main bundle. */
export default function FileEditor({ file, draft, canEdit, isSaving, onDraftChange, onSave }: FileEditorProps) {
    const [language, setLanguage] = useState<Extension[] | null>(null);
    const isDirty = draft !== file.content;

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
        () => (language === null ? null : [...language, themeHighlighting, changedLineGutter(file.headContent), EditorState.readOnly.of(!canEdit)]),
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
            <div className="flex items-center justify-between border-t border-hair px-4 py-2 text-[12px] text-muted">
                <span data-testid="file-save-state">{isDirty ? 'Unsaved. Claude is told when you save.' : 'Saved'}</span>
                {canEdit && (
                    <button
                        type="button"
                        data-testid="file-save"
                        disabled={!isDirty || isSaving}
                        onClick={onSave}
                        className="h-7 rounded-md bg-primary px-3 text-[12px] font-medium text-white disabled:opacity-50"
                    >
                        {isSaving ? 'Saving' : 'Save'}
                    </button>
                )}
            </div>
        </div>
    );
}
