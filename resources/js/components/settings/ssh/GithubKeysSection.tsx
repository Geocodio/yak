import { Badge, Button } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import { KeyRound, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { refresh } from '@/routes/settings/ssh-keys';
import type { SshKeysPageData } from '@/types/sshKeys';
import { KeyList } from './KeyList';
import { StaleNotice } from './StaleNotice';

export function GithubKeysSection({ keys, github }: { keys: SshKeysPageData['githubKeys']; github: SshKeysPageData['github'] }) {
    const [isRefreshing, setIsRefreshing] = useState(false);
    const refreshNow = () =>
        router.post(refresh.url(), {}, { preserveScroll: true, onStart: () => setIsRefreshing(true), onFinish: () => setIsRefreshing(false) });

    const empty = !github.linked ? (
        <>No GitHub account is linked to your Yak user. Link one in your profile and Yak will pick up its public keys, or add a key below.</>
    ) : (
        <div className="flex flex-col items-center gap-2 py-8 text-center" data-testid="ssh-github-empty">
            <KeyRound size={18} aria-hidden="true" />
            <h4 className="text-[13.5px] font-semibold text-text">GitHub has no public keys for you</h4>
            <p className="max-w-[320px] text-[12.5px] leading-relaxed">Add a key at github.com/settings/keys and Yak picks it up on your next connection, or upload one below to start now.</p>
            <a href="https://github.com/settings/keys" target="_blank" rel="noreferrer" className="mt-1 rounded-control border border-hair px-3 py-1.5 text-[12.5px] font-medium text-text hover:bg-panel-2">Open GitHub key settings</a>
        </div>
    );

    return (
        <section className="flex flex-col gap-3" aria-labelledby="ssh-github-heading">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 max-w-[560px]">
                    <h3 id="ssh-github-heading" className="flex items-center gap-2 text-[14px] font-semibold">
                        From your GitHub account <Badge>Read only</Badge>
                    </h3>
                    <p className="mt-1 text-[12.5px] leading-relaxed text-muted">
                        {github.linked && github.login ? (
                            <>
                                Yak trusts the public keys on{' '}
                                <a href={github.profileUrl ?? undefined} target="_blank" rel="noreferrer" className="font-mono text-accent-text hover:underline">
                                    github.com/{github.login}.keys
                                </a>{' '}
                                and refreshes the list each time you connect. Add or remove keys on GitHub.
                            </>
                        ) : (
                            'Yak trusts the public keys of the GitHub account linked to your user.'
                        )}
                    </p>
                </div>
                {github.linked && (
                    <Button variant="secondary" icon={<RefreshCw size={13} />} pending={isRefreshing} pendingLabel="Refreshing…" onClick={refreshNow} data-testid="ssh-refresh">
                        Refresh now
                    </Button>
                )}
            </div>
            {github.isStale && <StaleNotice checkedAt={github.checkedAt} />}
            <KeyList keys={keys} empty={empty} testId="ssh-github-keys" />
        </section>
    );
}
