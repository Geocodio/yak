import { EditorState, Compartment } from "@codemirror/state";
import { EditorView, keymap, highlightActiveLine, lineNumbers, Decoration, ViewPlugin } from "@codemirror/view";
import { defaultKeymap, history, historyKeymap, indentWithTab } from "@codemirror/commands";
import { autocompletion, completionKeymap } from "@codemirror/autocomplete";
import { MergeView } from "@codemirror/merge";

const BLADE_ECHO = /\{\{\s*\$([A-Za-z_][A-Za-z0-9_]*)\b[^}]*\}\}|\{!!\s*\$([A-Za-z_][A-Za-z0-9_]*)\b[^}]*!!\}/g;
const BLADE_DIRECTIVE = /@[a-zA-Z_][a-zA-Z0-9_]*/g;
const MARKDOWN_HEADING = /^##+ .+$/gm;

function bladeOverlay() {
    return ViewPlugin.fromClass(
        class {
            constructor(view) {
                this.decorations = this.build(view);
            }
            update(update) {
                if (update.docChanged || update.viewportChanged) {
                    this.decorations = this.build(update.view);
                }
            }
            build(view) {
                const marks = [];
                for (const { from, to } of view.visibleRanges) {
                    const text = view.state.doc.sliceString(from, to);
                    let m;
                    BLADE_ECHO.lastIndex = 0;
                    while ((m = BLADE_ECHO.exec(text)) !== null) {
                        marks.push(
                            Decoration.mark({ class: "cm-blade-echo" }).range(from + m.index, from + m.index + m[0].length),
                        );
                    }
                    BLADE_DIRECTIVE.lastIndex = 0;
                    while ((m = BLADE_DIRECTIVE.exec(text)) !== null) {
                        marks.push(
                            Decoration.mark({ class: "cm-blade-directive" }).range(from + m.index, from + m.index + m[0].length),
                        );
                    }
                    MARKDOWN_HEADING.lastIndex = 0;
                    while ((m = MARKDOWN_HEADING.exec(text)) !== null) {
                        marks.push(
                            Decoration.mark({ class: "cm-md-heading" }).range(from + m.index, from + m.index + m[0].length),
                        );
                    }
                }
                marks.sort((a, b) => a.from - b.from || a.to - b.to);
                return Decoration.set(marks, true);
            }
        },
        { decorations: (v) => v.decorations },
    );
}

function variableAutocomplete(variablesRef) {
    return autocompletion({
        override: [
            (context) => {
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
                        detail: "variable",
                    })),
                };
            },
        ],
    });
}

const yakTheme = EditorView.theme(
    {
        "&": {
            backgroundColor: "#2b3640",
            color: "#d4d4d4",
            fontSize: "14px",
            fontFamily: "'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace",
            height: "100%",
        },
        ".cm-content": { padding: "16px" },
        ".cm-scroller": { lineHeight: "1.55" },
        ".cm-gutters": { backgroundColor: "#232b33", color: "#7a8c9b", border: "none" },
        ".cm-activeLine, .cm-activeLineGutter": { backgroundColor: "rgba(255,255,255,0.04)" },
        ".cm-blade-echo": { color: "#9ec2e8", backgroundColor: "rgba(158,194,232,0.10)", borderRadius: "3px", padding: "0 2px" },
        ".cm-blade-directive": { color: "#c89bf0" },
        ".cm-md-heading": { color: "#f1c58b", fontWeight: "600" },
        ".cm-cursor": { borderLeftColor: "#ea7c2a" },
        "&.cm-focused .cm-selectionBackground, .cm-selectionBackground, ::selection": {
            backgroundColor: "rgba(234,124,42,0.30)",
        },
    },
    { dark: true },
);

function baseExtensions(variablesRef, ariaLabel = "Prompt editor") {
    return [
        history(),
        lineNumbers(),
        highlightActiveLine(),
        keymap.of([...defaultKeymap, ...historyKeymap, ...completionKeymap, indentWithTab]),
        bladeOverlay(),
        variableAutocomplete(variablesRef),
        yakTheme,
        EditorView.lineWrapping,
        EditorView.contentAttributes.of({ "aria-label": ariaLabel }),
    ];
}

export function promptEditor() {
    return {
        view: null,
        mergeView: null,
        variablesRef: { current: [] },
        inputCompartment: null,
        _applyingRemoteUpdate: false,

        init() {
            this.variablesRef.current = this.$wire.get("availableVariables") || [];

            this.inputCompartment = new Compartment();

            const updateListener = EditorView.updateListener.of((update) => {
                if (update.docChanged && !this._applyingRemoteUpdate) {
                    const value = update.state.doc.toString();
                    this.$wire.set("content", value, false);
                }
            });

            const state = EditorState.create({
                doc: this.$wire.get("content") || "",
                extensions: [
                    ...baseExtensions(this.variablesRef),
                    this.inputCompartment.of([updateListener]),
                ],
            });

            this.view = new EditorView({ state, parent: this.$refs.editor });

            this.$wire.$watch("content", (value) => {
                if (this.view && value !== this.view.state.doc.toString()) {
                    this._applyingRemoteUpdate = true;
                    this.view.dispatch({
                        changes: { from: 0, to: this.view.state.doc.length, insert: value ?? "" },
                    });
                    this._applyingRemoteUpdate = false;
                }
            });

            this.$wire.$watch("selectedSlug", () => {
                this.variablesRef.current = this.$wire.get("availableVariables") || [];
            });

            window.addEventListener("keydown", (e) => {
                if ((e.metaKey || e.ctrlKey) && e.key === "s" && this.$el.contains(document.activeElement)) {
                    e.preventDefault();
                    this.$wire.save();
                }
            });
        },

        destroy() {
            if (this.view) {
                this.view.destroy();
                this.view = null;
            }
            if (this.mergeView) {
                this.mergeView.destroy();
                this.mergeView = null;
            }
        },
    };
}

export function promptDiff() {
    return {
        merge: null,

        init() {
            this.renderMerge();
            this.$wire.$watch("content", () => this.renderMerge());
        },

        renderMerge() {
            if (this.merge) {
                this.merge.destroy();
                this.merge = null;
                this.$refs.diff.innerHTML = "";
            }
            const previous = this.$el.dataset.previous ?? "";
            const current = this.$wire.get("content") ?? "";
            this.merge = new MergeView({
                parent: this.$refs.diff,
                a: { doc: previous, extensions: [yakTheme, EditorView.lineWrapping, EditorView.editable.of(false), EditorView.contentAttributes.of({ "aria-label": "Previous version" })] },
                b: { doc: current, extensions: [yakTheme, EditorView.lineWrapping, EditorView.editable.of(false), EditorView.contentAttributes.of({ "aria-label": "Current draft" })] },
            });
        },

        destroy() {
            if (this.merge) {
                this.merge.destroy();
                this.merge = null;
            }
        },
    };
}
