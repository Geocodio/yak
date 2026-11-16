import { FitAddon } from '@xterm/addon-fit';
import { Terminal, type ITheme } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { useEffect, useRef, type MutableRefObject } from 'react';

export type TerminalControls = {
    clear: () => void;
    focus: () => void;
    isApplicationCursorKeys: () => boolean;
};

type Props = {
    /** Raw bytes the user typed (already text), to send to the shell. */
    onInput: (data: string) => void;
    onResize: (cols: number, rows: number) => void;
    /** Subscribes to bytes from the shell; returns the unsubscribe function. */
    subscribe: (callback: (chunk: Uint8Array) => void) => () => void;
    controls: MutableRefObject<TerminalControls | null>;
    label: string;
};

function token(name: string, fallback: string): string {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
}

function buildTheme(): ITheme {
    const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    return {
        background: token('--panel-2', dark ? '#1b1d21' : '#eef0f2'),
        foreground: token('--text', dark ? '#e6e8eb' : '#1d2125'),
        cursor: token('--text', dark ? '#e6e8eb' : '#1d2125'),
        selectionBackground: dark ? 'rgba(127, 156, 180, 0.35)' : 'rgba(74, 101, 128, 0.25)',
        green: dark ? '#7ccf8e' : '#2d7a3e',
        brightBlack: dark ? '#8a9099' : '#6b727b',
    };
}

/** The xterm.js surface. Lazy-loaded so the xterm bundle stays out of the thread page until the Terminal tab opens. */
export default function TerminalView({ onInput, onResize, subscribe, controls, label }: Props) {
    const host = useRef<HTMLDivElement | null>(null);
    const latest = useRef({ onInput, onResize });
    latest.current = { onInput, onResize };

    useEffect(() => {
        const element = host.current;
        if (element === null) {
            return;
        }

        const terminal = new Terminal({
            scrollback: 5000,
            cursorBlink: true,
            fontFamily: token('--font-mono', 'ui-monospace, SFMono-Regular, Menlo, monospace'),
            fontSize: 13,
            theme: buildTheme(),
        });
        const fit = new FitAddon();
        terminal.loadAddon(fit);
        terminal.open(element);
        terminal.textarea?.setAttribute('aria-label', label);

        let debounce: ReturnType<typeof setTimeout> | null = null;
        const refit = () => {
            try {
                fit.fit();
            } catch {
                return;
            }
            latest.current.onResize(terminal.cols, terminal.rows);
        };
        refit();

        const observer = new ResizeObserver(() => {
            if (debounce) {
                clearTimeout(debounce);
            }
            debounce = setTimeout(refit, 100);
        });
        observer.observe(element);

        const dataSubscription = terminal.onData((data) => latest.current.onInput(data));
        const unsubscribe = subscribe((chunk) => terminal.write(chunk));
        const scheme = window.matchMedia('(prefers-color-scheme: dark)');
        const retheme = () => {
            terminal.options.theme = buildTheme();
        };
        scheme.addEventListener('change', retheme);

        controls.current = {
            clear: () => terminal.clear(),
            focus: () => terminal.focus(),
            isApplicationCursorKeys: () => terminal.modes.applicationCursorKeysMode,
        };

        return () => {
            controls.current = null;
            scheme.removeEventListener('change', retheme);
            if (debounce) {
                clearTimeout(debounce);
            }
            observer.disconnect();
            unsubscribe();
            dataSubscription.dispose();
            terminal.dispose();
        };
    }, [subscribe, controls, label]);

    return <div ref={host} className="h-full w-full overflow-hidden p-2" data-testid="terminal-view" />;
}
