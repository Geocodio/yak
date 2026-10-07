import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { tags } from '@lezer/highlight';

/**
 * Syntax colours drawn from the app's theme variables, so code reads
 * properly in both the light and the dark theme (each variable is a
 * `light-dark()` pair). Covers the token types the legacy language modes
 * emit; anything unlisted stays body text.
 */
export const themeHighlighting = syntaxHighlighting(
    HighlightStyle.define([
        { tag: [tags.keyword, tags.controlKeyword, tags.moduleKeyword, tags.operatorKeyword, tags.definitionKeyword], color: 'var(--accent-text)' },
        { tag: [tags.string, tags.special(tags.string), tags.regexp, tags.inserted], color: 'var(--ok)' },
        { tag: [tags.number, tags.bool, tags.null, tags.atom, tags.unit], color: 'var(--warn)' },
        { tag: [tags.comment, tags.lineComment, tags.blockComment, tags.docComment], color: 'var(--text-3)', fontStyle: 'italic' },
        { tag: [tags.function(tags.variableName), tags.function(tags.propertyName), tags.propertyName, tags.attributeName], color: 'var(--info)' },
        { tag: [tags.typeName, tags.className, tags.namespace, tags.tagName, tags.standard(tags.variableName)], color: 'var(--accent)' },
        { tag: [tags.special(tags.variableName), tags.self, tags.labelName], color: 'var(--warn)' },
        { tag: [tags.meta, tags.processingInstruction, tags.annotation], color: 'var(--text-2)' },
        { tag: [tags.deleted, tags.invalid], color: 'var(--fail)' },
        { tag: tags.heading, fontWeight: '600', color: 'var(--accent-text)' },
        { tag: tags.link, textDecoration: 'underline' },
        { tag: tags.emphasis, fontStyle: 'italic' },
        { tag: tags.strong, fontWeight: '600' },
    ]),
);
