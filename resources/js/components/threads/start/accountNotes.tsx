import { Link } from '@inertiajs/react';
import type { NewThreadPageData } from '@/types/threads';

type PickerAccount = NewThreadPageData['picker']['accounts'][number];

export type AccountNote = {
    kind: 'none' | 'own-missing' | 'repo-default-broken';
    sharedName?: string;
    brokenName?: string;
    fallbackName?: string;
};

/**
 * Decides which note the "Runs on" strip shows. A broken repo default is only
 * named when the user has an own usable default to fall back on.
 */
export function resolveAccountNote(accounts: PickerAccount[], brokenDefaultAccountId: number | null): AccountNote {
    const broken = brokenDefaultAccountId === null ? undefined : accounts.find((account) => account.id === brokenDefaultAccountId && !account.usable);
    const fallback = accounts.find((account) => !account.isSystem && account.isDefault && account.usable && account.id !== broken?.id);

    if (broken && fallback) {
        return { kind: 'repo-default-broken', brokenName: broken.name, fallbackName: fallback.name };
    }

    const shared = accounts.find((account) => account.isSystem && account.usable);

    if (!accounts.some((account) => !account.isSystem) && shared) {
        return { kind: 'own-missing', sharedName: shared.name };
    }

    return { kind: 'none' };
}

export function NoOwnAccountNote({ sharedName, accountsUrl }: { sharedName: string; accountsUrl: string }) {
    return (
        <p data-testid="start-thread-account-note">
            You have no Claude account yet, so this task runs on the shared account {sharedName}.{' '}
            <Link href={accountsUrl} className="underline">
                Add your own
            </Link>
        </p>
    );
}

export function BrokenDefaultNote({ brokenName, fallbackName, accountsUrl }: { brokenName: string; fallbackName: string; accountsUrl: string }) {
    return (
        <p data-testid="start-thread-account-note">
            Your default for this repo, {brokenName}, needs a new secret, so this task runs on your default account, {fallbackName}.{' '}
            <Link href={accountsUrl} className="underline">
                Replace the secret
            </Link>
        </p>
    );
}
