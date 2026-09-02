import { Badge, Button, ConfirmDialog, Field, TextInput } from '@geocodio/console-ui';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import share from '@/routes/deployments/share';
import type { ShareLinkData } from '@/types/deployments';

export function ShareLinkCard({ deploymentId, shareLink, mintedUrl }: { deploymentId: number; shareLink: ShareLinkData; mintedUrl: string | null }) {
    const [shownUrl, setShownUrl] = useState(mintedUrl);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [revoking, setRevoking] = useState(false);
    const form = useForm({ expires_in_days: 7 });

    useEffect(() => {
        if (mintedUrl) {
            setShownUrl(mintedUrl);
        }
    }, [mintedUrl]);

    const mint = () => {
        form.post(share.store.url(deploymentId), { preserveScroll: true });
    };

    const revoke = () => {
        setRevoking(true);
        router.delete(share.destroy.url(deploymentId), {
            preserveScroll: true,
            onFinish: () => setRevoking(false),
        });
    };

    return (
        <section className="rounded-card border border-hair bg-panel shadow-card">
            <div className="flex items-center justify-between border-b border-hair px-4 py-2.5">
                <h2 className="text-[12px] font-semibold uppercase tracking-wide text-faint">Public share link</h2>
                {shareLink?.active && (
                    <Badge tone="ok">{shareLink.expiresAgo ? `Active · expires ${shareLink.expiresAgo}` : 'Active'}</Badge>
                )}
            </div>
            <div className="p-4">
                {shareLink?.active ? (
                    <>
                        <p className="text-[12px] text-muted">Anyone with the link can open this preview without signing in.</p>
                        <Button variant="destructive" className="mt-3" onClick={() => setConfirmOpen(true)}>
                            Revoke
                        </Button>
                    </>
                ) : (
                    <div className="flex items-end gap-2">
                        <Field label="Expires in (days)" className="flex-1">
                            <TextInput
                                type="number"
                                value={form.data.expires_in_days}
                                onChange={(e) => form.setData('expires_in_days', Number(e.target.value))}
                            />
                        </Field>
                        <Button pending={form.processing} onClick={mint}>
                            Generate share link
                        </Button>
                    </div>
                )}

                {shownUrl && (
                    <div className="mt-3 rounded-card border border-ok/30 bg-ok-soft/40 p-3">
                        <p className="mb-2 text-[12px]">Copy this link and share it. It will not be shown again.</p>
                        <div className="flex items-center gap-2">
                            <TextInput readOnly value={shownUrl} className="flex-1 font-mono" data-testid="minted-share-url" />
                        </div>
                        <Button variant="tertiary" className="mt-2" onClick={() => setShownUrl(null)}>
                            Done
                        </Button>
                    </div>
                )}
            </div>
            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title="Revoke the current share link"
                body="Anyone with the current link loses access. A new link will be different."
                confirmLabel="Revoke"
                busy={revoking}
                onConfirm={() => {
                    revoke();
                    setConfirmOpen(false);
                }}
            />
        </section>
    );
}
