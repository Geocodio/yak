import { Textarea, cn } from '@geocodio/console-ui';
import {
    useEffect,
    useImperativeHandle,
    useLayoutEffect,
    useMemo,
    useRef,
    type ChangeEvent,
    type ComponentProps,
    type DragEvent,
    type KeyboardEvent,
    type MouseEvent,
    type ReactNode,
    type Ref,
    type RefObject,
} from 'react';
import { findTokens, repairTokens, stripTokens, tokenAround, tokensForInsertion } from './attachmentTokens';
import type { AttachmentDraft, DraftAttachment } from './useAttachmentDraft';

export type AttachmentTextareaHandle = {
    /** Insert tokens for newly attached files at the caret (or the end, if the field was never focused). */
    insertTokens: (added: DraftAttachment[]) => void;
};

/** Computed styles the mirror copies so its text wraps exactly like the textarea's. */
const MIRRORED_STYLES = [
    'boxSizing',
    'paddingTop',
    'paddingRight',
    'paddingBottom',
    'paddingLeft',
    'borderTopWidth',
    'borderRightWidth',
    'borderBottomWidth',
    'borderLeftWidth',
    'fontFamily',
    'fontSize',
    'fontWeight',
    'fontStyle',
    'fontVariantLigatures',
    'fontFeatureSettings',
    'lineHeight',
    'letterSpacing',
    'wordSpacing',
    'textIndent',
    'textTransform',
    'tabSize',
] as const;

type Props = Omit<ComponentProps<typeof Textarea>, 'value' | 'onChange' | 'ref'> & {
    value: string;
    onValueChange: (value: string) => void;
    draft: AttachmentDraft;
    /** Classes for the wrapper, which carries the field's background now the textarea is see-through. */
    wrapperClassName?: string;
    ref?: Ref<AttachmentTextareaHandle>;
    /** Receives the underlying `<textarea>`, e.g. for a dialog's initial focus. */
    inputRef?: RefObject<HTMLTextAreaElement | null>;
};

/**
 * A textarea whose `[Image #1]` tokens behave as single blocks. The text is
 * drawn by a mirror layer behind a see-through textarea, which keeps native
 * editing, undo, IME and mobile keyboards while letting each token render
 * as a chip. Backspace and Delete take a whole token, the caret skips over
 * one, and any other edit that clips a token removes all of it. Removing a
 * token removes its attachment and vice versa. Hovering a token highlights
 * its attachment (and the attachment's tile highlights the token).
 */
export function AttachmentTextarea({ value, onValueChange, draft, wrapperClassName, className, ref, inputRef, ...rest }: Props) {
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const mirrorRef = useRef<HTMLDivElement>(null);
    const valueRef = useRef(value);
    valueRef.current = value;
    const hasFocused = useRef(false);
    const lastCaret = useRef(0);
    const usingPointer = useRef(false);
    const composing = useRef(false);
    const liveRefs = useMemo(() => new Set(draft.refs), [draft.refs]);
    const liveRefsRef = useRef(liveRefs);
    liveRefsRef.current = liveRefs;

    const setCaret = (position: number) => {
        requestAnimationFrame(() => textareaRef.current?.setSelectionRange(position, position));
    };

    /**
     * Apply an edit through the browser's own editing pipeline so it joins
     * the undo stack and fires the usual input event; fall back to setting
     * the value directly where `execCommand` is unavailable.
     */
    const replaceRange = (start: number, end: number, text: string) => {
        const textarea = textareaRef.current;
        if (!textarea) {
            return;
        }

        textarea.focus();
        textarea.setSelectionRange(start, end);

        const applied = text === '' ? document.execCommand('delete') : document.execCommand('insertText', false, text);

        if (!applied) {
            const current = valueRef.current;
            handleValue(current.slice(0, start) + text + current.slice(end), current);
            setCaret(start + text.length);
        }
    };

    useImperativeHandle(ref, () => ({
        insertTokens: (added) => {
            const textarea = textareaRef.current;
            const current = valueRef.current;
            const start = hasFocused.current && textarea ? textarea.selectionStart : current.length;
            const end = hasFocused.current && textarea ? textarea.selectionEnd : current.length;

            replaceRange(start, end, tokensForInsertion(current, start, end, added.map((item) => item.ref)));
        },
    }));

    /**
     * A token that left the text parks its attachment; a parked one whose
     * token comes back (undo, or the label typed again) is restored.
     */
    const handleValue = (next: string, previous: string) => {
        onValueChange(next);

        const returning = findTokens(next, new Set(draft.parkedRefs())).map((token) => token.ref);
        const before = findTokens(previous, liveRefsRef.current).map((token) => token.ref);
        const after = new Set(findTokens(next, liveRefsRef.current).map((token) => token.ref));
        const gone = before.filter((ref) => !after.has(ref));

        if (gone.length > 0) {
            draft.parkRefs(gone);
        }
        if (returning.length > 0) {
            draft.restoreRefs(returning);
        }
    };

    const onChange = (event: ChangeEvent<HTMLTextAreaElement>) => {
        const previous = valueRef.current;
        const repaired = composing.current ? null : repairTokens(previous, event.target.value, liveRefsRef.current);

        if (repaired) {
            handleValue(repaired.value, previous);
            setCaret(repaired.caret);
        } else {
            handleValue(event.target.value, previous);
        }
    };

    // A removed tile takes its token out of the text.
    const previousRefs = useRef(draft.refs);
    useEffect(() => {
        const gone = previousRefs.current.filter((ref) => !draft.refs.includes(ref));
        previousRefs.current = draft.refs;

        if (gone.length > 0) {
            const stripped = stripTokens(valueRef.current, gone);
            if (stripped !== valueRef.current) {
                onValueChange(stripped);
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [draft.refs]);

    /**
     * Set a selection by its fixed end (`anchor`) and moving end (`focus`),
     * keeping the direction so further Shift+Arrows move the right end.
     */
    const select = (textarea: HTMLTextAreaElement, anchor: number, focus: number) => {
        if (focus >= anchor) {
            textarea.setSelectionRange(anchor, focus, 'forward');
        } else {
            textarea.setSelectionRange(focus, anchor, 'backward');
        }
        lastCaret.current = focus;
    };

    /**
     * Shift+Arrow takes a token in one step: when the next character the
     * selection would gain or give back belongs to a token, its moving end
     * jumps to the token's far edge, so the token is selected or deselected
     * whole. Every other step is left to the browser.
     */
    const extendOverToken = (event: KeyboardEvent<HTMLTextAreaElement>): boolean => {
        if (!event.shiftKey || event.altKey || event.metaKey || event.ctrlKey || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight')) {
            return false;
        }

        const textarea = event.currentTarget;
        const backward = textarea.selectionDirection === 'backward';
        const focus = backward ? textarea.selectionStart : textarea.selectionEnd;
        const anchor = backward ? textarea.selectionEnd : textarea.selectionStart;
        const tokens = findTokens(valueRef.current, liveRefsRef.current);
        const token =
            event.key === 'ArrowRight'
                ? tokens.find((candidate) => candidate.start <= focus && focus < candidate.end)
                : tokens.find((candidate) => candidate.start < focus && focus <= candidate.end);

        if (!token) {
            return false;
        }

        event.preventDefault();
        select(textarea, anchor, event.key === 'ArrowRight' ? token.end : token.start);

        return true;
    };

    /**
     * Grow a selection whose ends landed inside tokens (a drag, a Shift+click,
     * Shift+Option/Cmd+Arrow) out to the tokens' edges, so a selection always
     * covers a token completely or not at all.
     */
    const expandSelectionToTokens = (textarea: HTMLTextAreaElement) => {
        const { selectionStart, selectionEnd, selectionDirection } = textarea;
        if (selectionStart === selectionEnd) {
            return;
        }

        const tokens = findTokens(valueRef.current, liveRefsRef.current);
        const start = tokens.find((token) => token.start < selectionStart && selectionStart < token.end)?.start ?? selectionStart;
        const end = tokens.find((token) => token.start < selectionEnd && selectionEnd < token.end)?.end ?? selectionEnd;

        if (start !== selectionStart || end !== selectionEnd) {
            textarea.setSelectionRange(start, end, selectionDirection);
        }
    };

    /** A click on a token selects all of it; a drag over one takes it whole. */
    const onMouseUp = (event: MouseEvent<HTMLTextAreaElement>) => {
        rest.onMouseUp?.(event);
        const textarea = event.currentTarget;

        if (textarea.selectionStart !== textarea.selectionEnd) {
            expandSelectionToTokens(textarea);
            return;
        }

        const ref = tokenAt(event.clientX, event.clientY);
        if (ref === null) {
            return;
        }

        const caret = textarea.selectionStart;
        const matches = findTokens(valueRef.current, liveRefsRef.current).filter((token) => token.ref === ref);
        const token = matches.find((candidate) => candidate.start <= caret && caret <= candidate.end) ?? matches[0];

        if (token) {
            textarea.setSelectionRange(token.start, token.end, 'forward');
            lastCaret.current = token.end;
        }
    };

    const onKeyUp = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        rest.onKeyUp?.(event);
        if (event.shiftKey || event.key === 'Shift') {
            expandSelectionToTokens(event.currentTarget);
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        rest.onKeyDown?.(event);
        usingPointer.current = false;

        const textarea = event.currentTarget;
        const { selectionStart, selectionEnd } = textarea;

        if (event.defaultPrevented || composing.current || extendOverToken(event)) {
            return;
        }

        if (selectionStart !== selectionEnd || event.altKey || event.metaKey || event.ctrlKey) {
            return;
        }

        const tokens = findTokens(valueRef.current, liveRefsRef.current);
        const target =
            event.key === 'Backspace'
                ? tokens.find((token) => token.start < selectionStart && selectionStart <= token.end)
                : event.key === 'Delete'
                  ? tokens.find((token) => token.start <= selectionStart && selectionStart < token.end)
                  : undefined;

        if (target) {
            event.preventDefault();
            replaceRange(target.start, target.end, '');
        }
    };

    /**
     * The caret never rests inside a token: arrow keys hop over it, and a
     * press lands on the nearer edge until the release selects the token.
     */
    const onSelect = () => {
        const textarea = textareaRef.current;
        if (!textarea || composing.current) {
            return;
        }

        const { selectionStart, selectionEnd } = textarea;

        if (selectionStart === selectionEnd) {
            const token = tokenAround(valueRef.current, liveRefsRef.current, selectionStart);

            if (token) {
                const snapTo = usingPointer.current
                    ? selectionStart - token.start < token.end - selectionStart
                        ? token.start
                        : token.end
                    : selectionStart > lastCaret.current
                      ? token.end
                      : token.start;
                textarea.setSelectionRange(snapTo, snapTo);
                lastCaret.current = snapTo;
                return;
            }
        }

        lastCaret.current = selectionEnd;
    };

    const syncScroll = () => {
        if (mirrorRef.current && textareaRef.current) {
            mirrorRef.current.scrollTop = textareaRef.current.scrollTop;
        }
    };

    // Match the mirror's box and type metrics to the textarea, and keep them
    // matched as the field grows (phone composer rows) or the window resizes.
    useLayoutEffect(() => {
        const textarea = textareaRef.current;
        const mirror = mirrorRef.current;
        if (!textarea || !mirror) {
            return;
        }

        const sync = () => {
            const computed = window.getComputedStyle(textarea);
            MIRRORED_STYLES.forEach((property) => {
                mirror.style[property] = computed[property];
            });
            const scrollbar =
                textarea.offsetWidth - textarea.clientWidth - parseFloat(computed.borderLeftWidth) - parseFloat(computed.borderRightWidth);
            mirror.style.paddingRight = `${parseFloat(computed.paddingRight) + Math.max(0, scrollbar)}px`;
            mirror.style.borderStyle = 'solid';
            mirror.style.borderColor = 'transparent';
            syncScroll();
        };

        sync();
        const observer = new ResizeObserver(sync);
        observer.observe(textarea);

        return () => observer.disconnect();
    }, []);

    useLayoutEffect(syncScroll, [value]);

    /**
     * The text offset under a point, read from the mirror (a textarea has no
     * caret-from-point API of its own). Null when the point isn't over text.
     */
    const offsetAt = (x: number, y: number): number | null => {
        const mirror = mirrorRef.current;
        const textarea = textareaRef.current;
        if (!mirror || !textarea) {
            return null;
        }

        mirror.style.pointerEvents = 'auto';
        textarea.style.pointerEvents = 'none';
        let node: Node | null = null;
        let offset = 0;
        try {
            if (document.caretPositionFromPoint) {
                const position = document.caretPositionFromPoint(x, y);
                node = position?.offsetNode ?? null;
                offset = position?.offset ?? 0;
            } else if (document.caretRangeFromPoint) {
                const range = document.caretRangeFromPoint(x, y);
                node = range?.startContainer ?? null;
                offset = range?.startOffset ?? 0;
            }
        } finally {
            mirror.style.pointerEvents = '';
            textarea.style.pointerEvents = '';
        }

        if (!node || node.nodeType !== Node.TEXT_NODE || !mirror.contains(node)) {
            return null;
        }

        const walker = document.createTreeWalker(mirror, NodeFilter.SHOW_TEXT);
        let total = 0;
        while (walker.nextNode()) {
            if (walker.currentNode === node) {
                return Math.min(total + offset, valueRef.current.length);
            }
            total += walker.currentNode.textContent?.length ?? 0;
        }

        return null;
    };

    /** Files dropped on the text go in where they land, never inside another token. */
    const onDrop = (event: DragEvent<HTMLTextAreaElement>) => {
        const position = offsetAt(event.clientX, event.clientY);
        if (position === null) {
            return;
        }

        const token = tokenAround(valueRef.current, liveRefsRef.current, position);
        const snapped = token ? (position - token.start < token.end - position ? token.start : token.end) : position;

        event.currentTarget.setSelectionRange(snapped, snapped);
        hasFocused.current = true;
    };

    const tokenAt = (x: number, y: number): string | null => {
        const chips = mirrorRef.current?.querySelectorAll<HTMLElement>('[data-attachment-ref]') ?? [];

        for (const chip of chips) {
            for (const rect of chip.getClientRects()) {
                if (x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom) {
                    return chip.dataset.attachmentRef ?? null;
                }
            }
        }

        return null;
    };

    const onMouseMove = (event: MouseEvent<HTMLTextAreaElement>) => {
        const ref = tokenAt(event.clientX, event.clientY);
        if (ref !== draft.highlighted && (ref !== null || draft.highlighted !== null)) {
            draft.setHighlighted(ref);
        }
    };

    const segments: ReactNode[] = [];
    let cursor = 0;
    for (const token of findTokens(value, liveRefs)) {
        segments.push(value.slice(cursor, token.start));
        segments.push(
            <span
                key={token.start}
                className="attachment-ref"
                data-attachment-ref={token.ref}
                data-highlighted={draft.highlighted === token.ref ? '' : undefined}
            >
                {value.slice(token.start, token.end)}
            </span>,
        );
        cursor = token.end;
    }
    // The trailing space lets a final empty line take up room, as it does in the textarea.
    segments.push(`${value.slice(cursor)} `);

    return (
        <div className={cn('relative', wrapperClassName)}>
            <div
                ref={mirrorRef}
                aria-hidden
                className="pointer-events-none absolute inset-0 overflow-hidden whitespace-pre-wrap wrap-break-word text-body"
                data-testid="attachment-textarea-mirror"
            >
                {segments}
            </div>
            <Textarea
                {...rest}
                ref={(element: HTMLTextAreaElement | null) => {
                    textareaRef.current = element;
                    if (inputRef) {
                        inputRef.current = element;
                    }
                }}
                value={value}
                onChange={onChange}
                onKeyDown={onKeyDown}
                onSelect={onSelect}
                onScroll={syncScroll}
                onMouseMove={onMouseMove}
                onMouseUp={onMouseUp}
                onKeyUp={onKeyUp}
                onDrop={onDrop}
                onMouseLeave={() => draft.highlighted !== null && draft.setHighlighted(null)}
                onPointerDown={() => {
                    usingPointer.current = true;
                }}
                onFocus={(event) => {
                    hasFocused.current = true;
                    rest.onFocus?.(event);
                }}
                onCompositionStart={() => {
                    composing.current = true;
                }}
                onCompositionEnd={() => {
                    composing.current = false;
                }}
                className={cn('relative block bg-transparent text-transparent caret-body selection:bg-accent/25', className)}
            />
        </div>
    );
}
