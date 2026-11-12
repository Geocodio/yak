import type { AccountKind } from '@/types/claudeAccounts';

/** Removes every space and line break, which a terminal adds when it wraps a long token. */
export function cleanSecret(raw: string): string {
    return raw.replace(/\s+/g, '');
}

/** Mirrors the server rule: `sk-ant-oat01-` is a setup token, any other `sk-ant-` value is an API key. */
export function detectKind(secret: string): AccountKind | null {
    if (secret.startsWith('sk-ant-oat01-')) {
        return 'setup_token';
    }

    return secret.startsWith('sk-ant-') ? 'api_key' : null;
}
