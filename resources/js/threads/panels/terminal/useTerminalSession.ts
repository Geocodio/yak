import { useCallback, useEffect, useRef, useState } from 'react';
import { csrfToken } from '@/lib/csrfToken';
import { store } from '@/routes/threads/terminal-sessions';
import { MAX_RECONNECT_TRIES, backoffDelay, classifyClose, decodeControl, encodeResize, refusalReason } from './terminalProtocol';

export type TerminalSessionState = 'idle' | 'connecting' | 'connected' | 'lost' | 'denied' | 'waiting' | 'unavailable';

/** Which step of opening a shell is in flight: the sandbox waking, the socket opening, or the wait for the first bytes. */
export type TerminalPhase = 'sandbox' | 'link' | 'shell';

/** Why Yak queued the sandbox for a slot, as the session endpoint reports it. */
export type TerminalLimit = 'person_share' | 'global_cap' | 'memory_floor';

export type TerminalSession = {
    state: TerminalSessionState;
    phase: TerminalPhase;
    limit: TerminalLimit | null;
    reason: string | null;
    connect: () => void;
    send: (data: string) => void;
    resize: (cols: number, rows: number) => void;
    onData: (callback: (chunk: Uint8Array) => void) => () => void;
};

const WAIT_POLL_MS = 2000;
export const WAIT_LIMIT_MS = 120_000;

/** Tests may set `window.__yakTerminalBackoff` to stretch or shrink the reconnect delays. */
function reconnectDelay(attempt: number): number {
    const override = (window as unknown as { __yakTerminalBackoff?: number[] }).__yakTerminalBackoff;

    return override ? override[Math.min(attempt, override.length - 1)] : backoffDelay(attempt);
}

/** One terminal shell: asks Yak for a one-time token, opens the bridge socket and reconnects with backoff. */
export function useTerminalSession(threadId: string): TerminalSession {
    const [state, setState] = useState<TerminalSessionState>('idle');
    const [reason, setReason] = useState<string | null>(null);
    const [phase, setPhase] = useState<TerminalPhase>('sandbox');
    const [limit, setLimit] = useState<TerminalLimit | null>(null);
    const socket = useRef<WebSocket | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const attempt = useRef(0);
    const generation = useRef(0);
    const listeners = useRef(new Set<(chunk: Uint8Array) => void>());
    const pending = useRef<Uint8Array[]>([]);
    const size = useRef<{ cols: number; rows: number } | null>(null);

    const emit = useCallback((chunk: Uint8Array) => {
        if (listeners.current.size === 0) {
            pending.current.push(chunk);

            return;
        }
        listeners.current.forEach((listener) => listener(chunk));
    }, []);

    const start = useCallback(
        async (waitStartedAt: number | null = null) => {
            const mine = ++generation.current;
            const stale = () => generation.current !== mine;
            setState(waitStartedAt === null ? 'connecting' : 'waiting');
            setReason(null);
            setPhase('sandbox');

            const scheduleRetry = (message: string) => {
                setState('lost');
                setReason(message);
                if (attempt.current < MAX_RECONNECT_TRIES) {
                    const delay = reconnectDelay(attempt.current++);
                    timer.current = setTimeout(() => void start(), delay);
                }
            };

            let response: Response;
            try {
                response = await fetch(store.url(threadId), {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
                    credentials: 'same-origin',
                });
            } catch {
                if (!stale()) {
                    scheduleRetry('Could not reach Yak.');
                }

                return;
            }
            if (stale()) {
                return;
            }

            if (response.status === 202) {
                const startedAt = waitStartedAt ?? Date.now();
                if (Date.now() - startedAt > WAIT_LIMIT_MS) {
                    setState('unavailable');
                    setReason('No sandbox slot became free. Try again in a moment.');

                    return;
                }
                const queued = (await response.json().catch(() => ({}))) as { limit?: TerminalLimit | null };
                if (stale()) {
                    return;
                }
                setLimit(queued.limit ?? null);
                setState('waiting');
                timer.current = setTimeout(() => void start(startedAt), WAIT_POLL_MS);

                return;
            }

            if (!response.ok) {
                const body = (await response.json().catch(() => ({}))) as { error?: string };
                if (stale()) {
                    return;
                }
                setState(response.status === 403 ? 'denied' : 'unavailable');
                setReason(refusalReason(response.status, body.error ?? null));

                return;
            }

            const { url } = (await response.json()) as { url: string };
            if (stale()) {
                return;
            }
            setLimit(null);
            setPhase('link');

            const ws = new WebSocket(url);
            ws.binaryType = 'arraybuffer';
            socket.current = ws;
            ws.onopen = () => {
                attempt.current = 0;
                setPhase('shell');
                if (size.current) {
                    ws.send(encodeResize(size.current.cols, size.current.rows));
                }
            };
            ws.onmessage = (event: MessageEvent) => {
                if (typeof event.data === 'string') {
                    if (decodeControl(event.data).type === 'exit') {
                        setState('lost');
                        setReason('The shell ended.');
                        attempt.current = MAX_RECONNECT_TRIES;
                    }

                    return;
                }
                setState('connected');
                emit(new Uint8Array(event.data as ArrayBuffer));
            };
            ws.onclose = (event: CloseEvent) => {
                if (socket.current !== ws || stale()) {
                    return;
                }
                socket.current = null;
                const outcome = classifyClose(event.code);
                if (outcome === 'refused') {
                    setState('denied');
                    setReason('The terminal session was refused. Reconnect to try again.');
                } else if (outcome === 'too_many') {
                    setState('unavailable');
                    setReason('Too many shells are open on this thread.');
                } else if (outcome === 'finished') {
                    setState('lost');
                    setReason('The shell ended.');
                } else {
                    scheduleRetry('The connection dropped.');
                }
            };
        },
        [emit, threadId],
    );

    const connect = useCallback(() => {
        if (timer.current) {
            clearTimeout(timer.current);
        }
        generation.current++;
        socket.current?.close();
        socket.current = null;
        attempt.current = 0;
        void start();
    }, [start]);

    useEffect(
        () => () => {
            generation.current++;
            if (timer.current) {
                clearTimeout(timer.current);
            }
            socket.current?.close();
            socket.current = null;
        },
        [],
    );

    const send = useCallback((data: string) => {
        if (socket.current?.readyState === WebSocket.OPEN) {
            socket.current.send(new TextEncoder().encode(data));
        }
    }, []);

    const resize = useCallback((cols: number, rows: number) => {
        size.current = { cols, rows };
        if (socket.current?.readyState === WebSocket.OPEN) {
            socket.current.send(encodeResize(cols, rows));
        }
    }, []);

    const onData = useCallback((callback: (chunk: Uint8Array) => void) => {
        listeners.current.add(callback);
        pending.current.splice(0).forEach(callback);

        return () => {
            listeners.current.delete(callback);
        };
    }, []);

    return { state, phase, limit, reason, connect, send, resize, onData };
}
