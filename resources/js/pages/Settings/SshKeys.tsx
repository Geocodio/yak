import { Button, ConfirmDialog } from '@geocodio/console-ui';
import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { AddKeyDialog } from '@/components/settings/ssh/AddKeyDialog';
import { ConfigSnippet } from '@/components/settings/ssh/ConfigSnippet';
import { GithubKeysSection } from '@/components/settings/ssh/GithubKeysSection';
import { KeyList } from '@/components/settings/ssh/KeyList';
import { SettingsLayout } from '@/layouts/SettingsLayout';
import { destroy } from '@/routes/settings/ssh-keys';
import type { PageProps } from '@/types/shared';
import type { SshKey, SshKeysPageData } from '@/types/sshKeys';

type Props = PageProps<SshKeysPageData>;

export default function SshKeys({ githubKeys, uploadedKeys, github, config }: Props) {
    const [isAdding, setIsAdding] = useState(false);
    const [removing, setRemoving] = useState<SshKey | null>(null);
    const [isBusy, setIsBusy] = useState(false);
    const hasNoKeys = githubKeys.length === 0 && uploadedKeys.length === 0;

    const confirmRemove = () => {
        if (removing === null) {
            return;
        }

        router.delete(destroy.url(removing.id), {
            preserveScroll: true,
            onStart: () => setIsBusy(true),
            onSuccess: () => setRemoving(null),
            onFinish: () => setIsBusy(false),
        });
    };

    return (
        <>
            <Head title="SSH keys" />

            <div className="flex flex-col gap-8">
                <div>
                    <p className="max-w-[620px] text-[13px] leading-relaxed text-muted">
                        These keys can run <code className="font-mono">yak ssh &lt;thread id&gt;</code> and use <code className="font-mono">scp</code> or an editor&apos;s remote mode against the sandboxes of threads you can drive.{' '}
                        <a href={config.docsUrl} target="_blank" rel="noreferrer" className="text-accent-text hover:underline">How yak ssh works</a>
                    </p>
                </div>

                {hasNoKeys && (
                    <p className="rounded-card border border-hair bg-panel-2 px-4 py-3 text-[13px]" data-testid="ssh-empty">
                        No SSH keys yet, so nobody can open a shell with your account. Link GitHub or add a key below.
                    </p>
                )}

                <GithubKeysSection keys={githubKeys} github={github} />

                <section className="flex flex-col gap-3" aria-labelledby="ssh-extra-heading">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 id="ssh-extra-heading" className="text-[14px] font-semibold">Extra keys</h3>
                            <p className="mt-1 text-[12.5px] text-muted">For a key that is not on GitHub, like one on a work computer or a hardware token.</p>
                        </div>
                        <Button variant="secondary" icon={<Plus size={13} />} onClick={() => setIsAdding(true)} data-testid="ssh-add-key">
                            Add a key
                        </Button>
                    </div>
                    <KeyList keys={uploadedKeys} onRemove={setRemoving} empty={<>No extra keys. You do not need any if your GitHub keys already cover the computers you connect from.</>} testId="ssh-extra-keys" />
                </section>

                <ConfigSnippet command={config.command} snippet={config.snippet} />
            </div>

            <AddKeyDialog open={isAdding} onOpenChange={setIsAdding} />
            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Remove ${removing?.name ?? ''}?`}
                body="This key can no longer open a shell in your sandboxes. Sessions already open keep running until they end."
                confirmLabel="Remove key"
                busy={isBusy}
                onConfirm={confirmRemove}
            />
        </>
    );
}

SshKeys.layout = (page: ReactNode) => <SettingsLayout slug="ssh-keys">{page}</SettingsLayout>;
