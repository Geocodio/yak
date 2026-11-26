import { setEmbedOnline } from './connectivity.ts';
import { setEmbedOutbox, type OutboxItem } from './outboxSource.ts';

/** The contract version of every message; it rises only on a breaking change. */
export const BRIDGE_VERSION = 1;

export const CARDS = ['question', 'co_owner', 'repository_question', 'stopped', 'early_warning'] as const;
export const OUTBOX_STATUSES = ['queued', 'sending', 'slow', 'waiting', 'refused'] as const;

export type PanePayload =
    | { pane: 'files'; mode: 'changed' | 'everything'; path?: string }
    | { pane: 'preview'; url?: string }
    | { pane: 'terminal' }
    | { pane: 'overview'; section?: 'run' | 'subthreads' | 'files' };

/** Page to app. */
export type PageMessage =
    | { type: 'ready'; threadId?: string; url: string; latestSequence?: number; title: string }
    | { type: 'threadChanged'; threadId: string; previousThreadId: string }
    | { type: 'link'; url: string }
    | ({ type: 'openPane' } & PanePayload)
    | { type: 'quickLook'; url: string; name: string; mime?: string }
    | { type: 'cardAnswered'; card: (typeof CARDS)[number]; action: string; ok: boolean }
    | { type: 'unsentAction'; key: string; action: 'retry' | 'edit' | 'discard' }
    | { type: 'auth'; state: 'expired' }
    | { type: 'focusMissed'; fragment: string }
    | { type: 'agents'; running: number; newest: string | null }
    | { type: 'error'; kind: 'http' | 'network' | 'script'; status?: number };

/** App to page. */
export type AppMessage =
    | { type: 'open'; threadId: string; fragment?: string }
    | { type: 'focus'; fragment: string }
    | { type: 'scrollToBottom' }
    | { type: 'outbox'; threadId: string; items: OutboxItem[] }
    | { type: 'refresh' }
    | { type: 'connectivity'; online: boolean };

export const PAGE_MESSAGE_TYPES = ['ready', 'threadChanged', 'link', 'openPane', 'quickLook', 'cardAnswered', 'unsentAction', 'auth', 'focusMissed', 'agents', 'error'] as const;
export const APP_MESSAGE_TYPES = ['open', 'focus', 'scrollToBottom', 'outbox', 'refresh', 'connectivity'] as const;

export type Validation<T> = { kind: 'ok'; message: T } | { kind: 'unknown' } | { kind: 'malformed'; reason: string };

type Shape = Record<string, unknown>;

const THREAD_ID = /^[A-Za-z0-9]{8}$/;
const FRAGMENT = /^[A-Za-z0-9_-]{1,64}$/;

const isRecord = (value: unknown): value is Shape => typeof value === 'object' && value !== null && !Array.isArray(value);
const isString = (value: unknown, max = 2048): value is string => typeof value === 'string' && value.length <= max;
const isNonEmpty = (value: unknown, max = 2048): value is string => isString(value, max) && value !== '';
const isCount = (value: unknown): value is number => typeof value === 'number' && Number.isInteger(value) && value >= 0;
const isOneOf = <T extends string>(value: unknown, options: readonly T[]): value is T => typeof value === 'string' && (options as readonly string[]).includes(value);
const isOptional = (value: unknown, check: (value: unknown) => boolean): boolean => value === undefined || check(value);
const isThreadId = (value: unknown): value is string => typeof value === 'string' && THREAD_ID.test(value);
const isFragment = (value: unknown): value is string => typeof value === 'string' && FRAGMENT.test(value);

function isOutboxItem(value: unknown): value is OutboxItem {
    return isRecord(value) && isNonEmpty(value.key, 100) && isString(value.text, 100_000) && isOneOf(value.status, OUTBOX_STATUSES) && (value.reason === null || isString(value.reason, 1000)) && isNonEmpty(value.createdAt, 64) && isCount(value.attachmentCount);
}

const pageChecks: Record<(typeof PAGE_MESSAGE_TYPES)[number], (message: Shape) => boolean> = {
    // A thread page names its thread and feed position; the Deployments and PR review pages carry neither.
    ready: (m) => isNonEmpty(m.url) && !(m.url as string).includes('\\') && isString(m.title, 500) && (m.threadId === undefined ? m.latestSequence === undefined : isThreadId(m.threadId) && isCount(m.latestSequence)),
    threadChanged: (m) => isThreadId(m.threadId) && isThreadId(m.previousThreadId),
    link: (m) => isNonEmpty(m.url),
    openPane: (m) =>
        (m.pane === 'files' && isOneOf(m.mode, ['changed', 'everything'] as const) && isOptional(m.path, (path) => isNonEmpty(path))) ||
        (m.pane === 'preview' && isOptional(m.url, (url) => isNonEmpty(url))) ||
        m.pane === 'terminal' ||
        (m.pane === 'overview' && isOptional(m.section, (section) => isOneOf(section, ['run', 'subthreads', 'files'] as const))),
    quickLook: (m) => isNonEmpty(m.url) && isNonEmpty(m.name, 500) && isOptional(m.mime, (mime) => isString(mime, 200)),
    cardAnswered: (m) => isOneOf(m.card, CARDS) && isNonEmpty(m.action, 64) && typeof m.ok === 'boolean',
    unsentAction: (m) => isNonEmpty(m.key, 100) && isOneOf(m.action, ['retry', 'edit', 'discard'] as const),
    auth: (m) => m.state === 'expired',
    focusMissed: (m) => isFragment(m.fragment),
    agents: (m) => isCount(m.running) && (m.newest === null || isFragment(m.newest)),
    error: (m) => isOneOf(m.kind, ['http', 'network', 'script'] as const) && isOptional(m.status, isCount),
};

const appChecks: Record<(typeof APP_MESSAGE_TYPES)[number], (message: Shape) => boolean> = {
    open: (m) => isThreadId(m.threadId) && isOptional(m.fragment, isFragment),
    focus: (m) => isFragment(m.fragment),
    scrollToBottom: () => true,
    outbox: (m) => isThreadId(m.threadId) && Array.isArray(m.items) && m.items.length <= 100 && m.items.every(isOutboxItem),
    refresh: () => true,
    connectivity: (m) => typeof m.online === 'boolean',
};

function validate<T>(raw: unknown, checks: Record<string, (message: Shape) => boolean>): Validation<T> {
    if (!isRecord(raw)) {
        return { kind: 'malformed', reason: 'not an object' };
    }
    if (raw.v !== BRIDGE_VERSION) {
        return { kind: 'malformed', reason: 'unsupported version' };
    }
    if (typeof raw.type !== 'string' || !Object.hasOwn(checks, raw.type)) {
        return { kind: 'unknown' };
    }

    return checks[raw.type](raw) ? { kind: 'ok', message: raw as T } : { kind: 'malformed', reason: `bad ${raw.type} payload` };
}

export const validatePageMessage = (raw: unknown): Validation<PageMessage & { v: number }> => validate(raw, pageChecks);
export const validateAppMessage = (raw: unknown): Validation<AppMessage & { v: number }> => validate(raw, appChecks);

declare global {
    interface Window {
        webkit?: { messageHandlers?: { yak?: { postMessage: (message: unknown) => void } } };
        __yakEmbed?: { receive: (message: unknown) => void };
        __yakEmbedLog?: unknown[];
    }
}

/** Only pages rendered for the test suite carry this marker, and only they keep a log of what they would have sent. */
const isLogging = (): boolean => typeof document !== 'undefined' && document.querySelector('meta[name="yak-embed-log"]') !== null;

/** Posts a message to the app, or logs it where there is no app (tests). Returns whether the app got it. */
export function sendToApp(message: PageMessage): boolean {
    const envelope = { v: BRIDGE_VERSION, ...message };

    if (validatePageMessage(envelope).kind !== 'ok') {
        return false;
    }

    const handler = window.webkit?.messageHandlers?.yak;

    if (handler !== undefined) {
        handler.postMessage(envelope);

        return true;
    }

    if (isLogging()) {
        (window.__yakEmbedLog ??= []).push(envelope);
    }

    return false;
}

/** The app's way in: `window.__yakEmbed.receive(message)`. Unknown and malformed messages are dropped. */
export function receiveFromApp(raw: unknown): void {
    const result = validateAppMessage(raw);

    if (result.kind !== 'ok' || !isActive) {
        return;
    }

    if (result.message.type === 'outbox') {
        setEmbedOutbox(result.message.threadId, result.message.items);
    } else if (result.message.type === 'connectivity') {
        setEmbedOnline(result.message.online);
    }

    window.dispatchEvent(new CustomEvent('yak:embed', { detail: result.message }));
}

let isActive = false;

/** Set while an embed page is mounted, so page-wide handlers (the 419 reload) stand aside for the app's own. */
export const setEmbedActive = (value: boolean): void => {
    isActive = value;
};
export const isEmbedActive = (): boolean => isActive;

export function installReceiver(): void {
    window.__yakEmbed = { receive: receiveFromApp };
}

/** The URL as the app should fetch it, or null when it leaves this origin (the session cookie must not travel there). */
export function sameOriginUrl(href: string, base: string = window.location.href): string | null {
    const url = new URL(href, base);

    return url.origin === new URL(base).origin ? url.href : null;
}

let isAuthReported = false;

/** Tells the app the session ended, once per page load, and only while an embed page is mounted. */
export function reportAuthExpired(): void {
    if (isActive && !isAuthReported) {
        isAuthReported = true;
        sendToApp({ type: 'auth', state: 'expired' });
    }
}

/** Reports the session ending when a response says so (401 expired, 419 token mismatch). */
export function reportAuthIfExpired(status: number): void {
    if (status === 401 || status === 419) {
        reportAuthExpired();
    }
}
