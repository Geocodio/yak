import { IconButton } from '@geocodio/console-ui';
import { usePage } from '@inertiajs/react';
import { Eraser } from 'lucide-react';
import { Suspense, lazy, useCallback, useEffect, useRef, useState } from 'react';
import type { PanelProps } from '@/threads/panels';
import type { ThreadPageData } from '@/types/threads';
import { KeyRow, useShowKeyRow } from './KeyRow';
import { applyCtrl } from './keyCodes';
import { BlockedState, LostBanner, SessionState, blockedState } from './TerminalStates';
import { TerminalStatus } from './TerminalStatus';
import { MAX_TERMINAL_TABS, TerminalTabs, type TerminalTab } from './TerminalTabs';
import type { TerminalControls } from './TerminalView';
import { useTerminalSession, type TerminalSessionState } from './useTerminalSession';

const TerminalView = lazy(() => import('./TerminalView'));

type SessionProps = {
    threadId: string;
    sandboxState: PanelProps['thread']['sandboxState'];
    active: boolean;
    clearToken: number;
    onState: (state: TerminalSessionState) => void;
};

/** One shell: its own token, socket and xterm. Inactive tabs stay mounted so their shells keep running. */
function TerminalSessionBody({ threadId, sandboxState, active, clearToken, onState }: SessionProps) {
    const session = useTerminalSession(threadId);
    const { state, reason, connect, send, resize, onData } = session;
    const controls = useRef<TerminalControls | null>(null);
    const [ctrlArmed, setCtrlArmed] = useState(false);
    const ctrlRef = useRef(false);
    const showKeyRow = useShowKeyRow();

    useEffect(() => {
        connect();
    }, [connect]);

    useEffect(() => onState(state), [state, onState]);

    useEffect(() => {
        if (clearToken > 0) {
            controls.current?.clear();
        }
    }, [clearToken]);

    const setCtrl = (armed: boolean) => {
        ctrlRef.current = armed;
        setCtrlArmed(armed);
    };

    const onInput = useCallback(
        (data: string) => {
            if (ctrlRef.current) {
                setCtrl(false);
                send(applyCtrl(data) ?? data);

                return;
            }
            send(data);
        },
        [send],
    );

    const sendFromKeyRow = useCallback(
        (data: string) => {
            send(data);
            controls.current?.focus();
        },
        [send],
    );

    return (
        <div className={active ? 'flex min-h-0 flex-1 flex-col' : 'hidden'}>
            {state === 'lost' && <LostBanner onReconnect={connect} />}
            <div className="relative min-h-0 flex-1 bg-panel-2">
                <Suspense fallback={null}>
                    <TerminalView onInput={onInput} onResize={resize} subscribe={onData} controls={controls} label="Terminal" />
                </Suspense>
                {state !== 'connected' && state !== 'idle' && state !== 'lost' && (
                    <div className="absolute inset-0 bg-sidebar">
                        <SessionState state={state} reason={reason} sandboxState={sandboxState} onReconnect={connect} />
                    </div>
                )}
            </div>
            {showKeyRow && <KeyRow send={sendFromKeyRow} controls={controls} ctrlArmed={ctrlArmed} onToggleCtrl={() => setCtrl(!ctrlRef.current)} />}
        </div>
    );
}

export function TerminalPanel({ thread, canDrive }: PanelProps) {
    const { teleport } = usePage<ThreadPageData & Record<string, unknown>>().props;
    const [tabs, setTabs] = useState<TerminalTab[]>([{ id: 1, title: 'main' }]);
    const [activeId, setActiveId] = useState(1);
    const [states, setStates] = useState<Record<number, TerminalSessionState>>({});
    const [clearToken, setClearToken] = useState(0);
    const nextId = useRef(2);

    const blocked = blockedState(thread, canDrive);
    if (blocked !== null) {
        return (
            <div className="flex h-full flex-col" data-testid="terminal-panel">
                <BlockedState kind={blocked} teleport={teleport} />
            </div>
        );
    }

    const addTab = () => {
        if (tabs.length >= MAX_TERMINAL_TABS) {
            return;
        }
        const id = nextId.current++;
        const wantsDevServer = thread.preview.port !== null && !tabs.some((tab) => tab.title === 'dev server');
        setTabs([...tabs, { id, title: wantsDevServer ? 'dev server' : `shell ${id}` }]);
        setActiveId(id);
    };

    const closeTab = (id: number) => {
        const remaining = tabs.filter((tab) => tab.id !== id);
        setTabs(remaining);
        if (activeId === id) {
            setActiveId(remaining[0].id);
        }
    };

    return (
        <div className="flex h-full min-h-0 flex-col" data-testid="terminal-panel">
            <div className="flex shrink-0 items-center justify-between gap-3 border-b border-hair px-3 py-2">
                <TerminalTabs tabs={tabs} activeId={activeId} onSelect={setActiveId} onAdd={addTab} onClose={closeTab} />
                <div className="flex shrink-0 items-center gap-2">
                    <TerminalStatus state={states[activeId] ?? 'idle'} />
                    <IconButton label="Clear terminal" data-testid="terminal-clear" onClick={() => setClearToken((value) => value + 1)}>
                        <Eraser size={14} />
                    </IconButton>
                </div>
            </div>
            {tabs.map((tab) => (
                <TerminalSessionBody
                    key={tab.id}
                    threadId={thread.id}
                    sandboxState={thread.sandboxState}
                    active={tab.id === activeId}
                    clearToken={clearToken}
                    onState={(state) => setStates((current) => (current[tab.id] === state ? current : { ...current, [tab.id]: state }))}
                />
            ))}
        </div>
    );
}
