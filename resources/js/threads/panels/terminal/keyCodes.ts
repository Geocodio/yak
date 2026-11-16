export type Arrow = 'up' | 'down' | 'left' | 'right';

const ARROW_FINAL: Record<Arrow, string> = { up: 'A', down: 'B', right: 'C', left: 'D' };

/** The bytes an arrow key sends; applications that enable cursor-key mode expect `ESC O` instead of `ESC [`. */
export function arrowSequence(direction: Arrow, applicationCursorKeys: boolean): string {
    return `\x1b${applicationCursorKeys ? 'O' : '['}${ARROW_FINAL[direction]}`;
}

export const ESCAPE = '\x1b';
export const TAB = '\t';
export const INTERRUPT = '\x03';

/** Extra keys for the scrolling row under the main keys. */
export const SYMBOL_KEYS = ['|', '/', '~', '-', '$', '_', '.', ':', '&', '>', '<'];

/** Turns one typed character into its control code (letters and `@[\]^_`), or null when Ctrl does not apply. */
export function applyCtrl(data: string): string | null {
    if (data.length !== 1) {
        return null;
    }
    const code = data.toUpperCase().charCodeAt(0);

    return code >= 0x40 && code <= 0x5f ? String.fromCharCode(code & 0x1f) : null;
}
