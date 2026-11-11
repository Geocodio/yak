import { cn } from '@geocodio/console-ui';
import { ArrowDown, ArrowLeft, ArrowRight, ArrowUp } from 'lucide-react';
import { useEffect, useState, type MutableRefObject, type ReactNode } from 'react';
import { ESCAPE, INTERRUPT, SYMBOL_KEYS, TAB, arrowSequence, type Arrow } from './keyCodes';
import type { TerminalControls } from './TerminalView';

const PHONE_QUERY = '(max-width: 767px), (pointer: coarse)';

/** True on narrow screens and touch devices, where the key row replaces the missing hardware keys. */
export function useShowKeyRow(): boolean {
    const [matches, setMatches] = useState(() => window.matchMedia(PHONE_QUERY).matches);
    useEffect(() => {
        const query = window.matchMedia(PHONE_QUERY);
        const update = () => setMatches(query.matches);
        query.addEventListener('change', update);
        update();

        return () => query.removeEventListener('change', update);
    }, []);

    return matches;
}

/** How far the on-screen keyboard covers the layout viewport, so the row can ride above it. */
function useKeyboardOffset(): number {
    const [offset, setOffset] = useState(0);
    useEffect(() => {
        const viewport = window.visualViewport;
        if (!viewport) {
            return;
        }
        const update = () => setOffset(Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop));
        viewport.addEventListener('resize', update);
        viewport.addEventListener('scroll', update);
        update();

        return () => {
            viewport.removeEventListener('resize', update);
            viewport.removeEventListener('scroll', update);
        };
    }, []);

    return offset;
}

type KeyProps = { testId?: string; label: ReactNode; ariaLabel?: string; pressed?: boolean; onPress: () => void; wide?: boolean };

function Key({ testId, label, ariaLabel, pressed, onPress, wide }: KeyProps) {
    return (
        <button
            type="button"
            data-testid={testId}
            aria-label={ariaLabel}
            aria-pressed={pressed}
            onPointerDown={(event) => {
                event.preventDefault();
                onPress();
            }}
            onClick={(event) => {
                // A click with no pointer (keyboard Enter or Space) has detail 0; pointer taps were handled above.
                if (event.detail === 0) {
                    onPress();
                }
            }}
            className={cn(
                'flex h-10 shrink-0 items-center justify-center rounded-md border border-hair bg-app text-[13px] font-medium text-body select-none',
                wide ? 'min-w-12 px-3' : 'min-w-10 px-2',
                pressed && 'border-brand bg-brand-soft text-brand',
            )}
        >
            {label}
        </button>
    );
}

type Props = {
    send: (data: string) => void;
    controls: MutableRefObject<TerminalControls | null>;
    ctrlArmed: boolean;
    onToggleCtrl: () => void;
};

/** Esc, Tab, a sticky Ctrl, arrows and common symbols for phone keyboards. */
export function KeyRow({ send, controls, ctrlArmed, onToggleCtrl }: Props) {
    const offset = useKeyboardOffset();
    const arrow = (direction: Arrow, icon: ReactNode) => (
        <Key
            testId={`terminal-key-${direction}`}
            ariaLabel={`Arrow ${direction}`}
            label={icon}
            onPress={() => send(arrowSequence(direction, controls.current?.isApplicationCursorKeys() ?? false))}
        />
    );

    return (
        <div
            className="flex shrink-0 items-center gap-1.5 overflow-x-auto border-t border-hair bg-sidebar px-2 py-2"
            style={offset > 0 ? { transform: `translateY(-${offset}px)` } : undefined}
            data-testid="terminal-keyrow"
        >
            <Key testId="terminal-key-esc" label="Esc" wide onPress={() => send(ESCAPE)} />
            <Key testId="terminal-key-tab" label="Tab" wide onPress={() => send(TAB)} />
            <Key testId="terminal-key-ctrl" label="Ctrl" wide pressed={ctrlArmed} onPress={onToggleCtrl} />
            <span className="mx-1 h-6 w-px shrink-0 bg-[var(--border)]" aria-hidden />
            {arrow('left', <ArrowLeft size={16} />)}
            {arrow('up', <ArrowUp size={16} />)}
            {arrow('down', <ArrowDown size={16} />)}
            {arrow('right', <ArrowRight size={16} />)}
            <span className="mx-1 h-6 w-px shrink-0 bg-[var(--border)]" aria-hidden />
            <Key testId="terminal-key-interrupt" label="Ctrl+C" wide onPress={() => send(INTERRUPT)} />
            {SYMBOL_KEYS.map((symbol) => (
                <Key key={symbol} label={symbol} onPress={() => send(symbol)} />
            ))}
        </div>
    );
}
