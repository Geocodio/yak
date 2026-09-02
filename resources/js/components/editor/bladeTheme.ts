import { autocompletion, type CompletionSource } from '@codemirror/autocomplete';
import { EditorView, ViewPlugin, Decoration, type DecorationSet, type ViewUpdate } from '@codemirror/view';

const BLADE_ECHO = /\{\{\s*\$([A-Za-z_][A-Za-z0-9_]*)\b[^}]*\}\}|\{!!\s*\$([A-Za-z_][A-Za-z0-9_]*)\b[^}]*!!\}/g;
const BLADE_DIRECTIVE = /@[a-zA-Z_][a-zA-Z0-9_]*/g;
const MARKDOWN_HEADING = /^##+ .+$/gm;

/**
 * Highlights Blade `{{ $var }}` echoes, `@directive`s, and `##` markdown
 * headings inline, without a full language grammar -- prompts mix Blade and
 * Markdown, and neither `@codemirror/lang-php` nor `@codemirror/lang-markdown`
 * models that combination. Ported from `resources/js/prompt-editor.js`.
 */
export function bladeOverlay() {
    return ViewPlugin.fromClass(
        class {
            decorations: DecorationSet;

            constructor(view: EditorView) {
                this.decorations = this.build(view);
            }

            update(update: ViewUpdate) {
                if (update.docChanged || update.viewportChanged) {
                    this.decorations = this.build(update.view);
                }
            }

            build(view: EditorView): DecorationSet {
                const marks = [];
                for (const { from, to } of view.visibleRanges) {
                    const text = view.state.doc.sliceString(from, to);
                    let m: RegExpExecArray | null;

                    BLADE_ECHO.lastIndex = 0;
                    while ((m = BLADE_ECHO.exec(text)) !== null) {
                        marks.push(Decoration.mark({ class: 'cm-blade-echo' }).range(from + m.index, from + m.index + m[0].length));
                    }

                    BLADE_DIRECTIVE.lastIndex = 0;
                    while ((m = BLADE_DIRECTIVE.exec(text)) !== null) {
                        marks.push(Decoration.mark({ class: 'cm-blade-directive' }).range(from + m.index, from + m.index + m[0].length));
                    }

                    MARKDOWN_HEADING.lastIndex = 0;
                    while ((m = MARKDOWN_HEADING.exec(text)) !== null) {
                        marks.push(Decoration.mark({ class: 'cm-md-heading' }).range(from + m.index, from + m.index + m[0].length));
                    }
                }
                marks.sort((a, b) => a.from - b.from || a.to - b.to);
                return Decoration.set(marks, true);
            }
        },
        { decorations: (v) => v.decorations },
    );
}

/** Autocompletes `{{ $var }}` for the prompt's declared variables. */
export function variableAutocomplete(variablesRef: { current: string[] }) {
    const source: CompletionSource = (context) => {
        const before = context.matchBefore(/\{\{\s*\$?\w*/);
        if (!before || before.text.length < 2) {
            return null;
        }
        const vars = variablesRef.current || [];
        if (vars.length === 0) {
            return null;
        }
        return {
            from: before.from,
            options: vars.map((v) => ({
                label: `{{ $${v} }}`,
                apply: `{{ $${v} }}`,
                detail: 'variable',
            })),
        };
    };

    return autocompletion({ override: [source] });
}

/** Shared theme for the prompt editor and diff view, tokenized on console-ui's CSS variables. */
export const bladeTheme = EditorView.theme({
    '&': {
        fontSize: '12.5px',
        backgroundColor: 'var(--panel)',
        color: 'var(--text)',
        height: '100%',
        fontFamily: 'var(--font-mono)',
    },
    '.cm-content': { padding: '12px 0' },
    '.cm-gutters': { backgroundColor: 'var(--panel)', color: 'var(--text-3)', borderRight: '1px solid var(--border)' },
    '.cm-activeLine, .cm-activeLineGutter': { backgroundColor: 'var(--panel-2)' },
    '&.cm-focused': { outline: 'none' },
    '.cm-selectionBackground, &.cm-focused .cm-selectionBackground': { backgroundColor: 'var(--accent-soft)' },
    '.cm-mergeView, .cm-merge-a, .cm-merge-b': { height: '100%' },
    '.cm-changedLine': { backgroundColor: 'var(--warn-soft)' },
    '.cm-deletedChunk': { backgroundColor: 'var(--fail-soft)' },
    '.cm-changedText': { background: 'var(--warn-soft)' },
    '.cm-blade-echo': { color: 'var(--accent-text)', backgroundColor: 'var(--accent-soft)', borderRadius: '3px', padding: '0 2px' },
    '.cm-blade-directive': { color: 'var(--accent)' },
    '.cm-md-heading': { color: 'var(--warn)', fontWeight: '600' },
});
