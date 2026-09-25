import { Head, router, usePoll } from '@inertiajs/react';
import { Badge, Button, ConfirmDialog, PageHeader, StatusPill } from '@geocodio/console-ui';
import { AlertTriangle, ExternalLink, RotateCw, Trash2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { ActivityLog } from '@/components/deployments/ActivityLog';
import { HibernationCard } from '@/components/deployments/HibernationCard';
import { ManifestCard } from '@/components/deployments/ManifestCard';
import { ShareLinkCard } from '@/components/deployments/ShareLinkCard';
import deployments from '@/routes/deployments';
import type { ManifestData } from '@/types/repositories';
import type { DeploymentDetail, DeploymentLogEntry, HibernationData, ShareLinkData } from '@/types/deployments';
import type { PageProps } from '@/types/shared';

type Props = PageProps<{
    deployment: DeploymentDetail;
    hibernation: HibernationData;
    manifest: ManifestData;
    shareLink: ShareLinkData;
    mintedUrl: string | null;
    logs: DeploymentLogEntry[];
    pollInterval: number;
}>;

export default function Show({ deployment, hibernation, manifest, shareLink, mintedUrl, logs, pollInterval }: Props) {
    usePoll(pollInterval);
    const [confirm, setConfirm] = useState<'rebuild' | 'destroy' | null>(null);
    const [busy, setBusy] = useState(false);

    const rebuild = () => {
        setBusy(true);
        router.post(
            deployments.rebuild.url(deployment.id),
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const destroy = () => {
        setBusy(true);
        router.delete(deployments.destroy.url(deployment.id), {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <>
            <Head title={deployment.branch} />
            <PageHeader
                crumbs={['Deployments', `${deployment.repoSlug} / ${deployment.branch}`]}
                actions={
                    <Button variant="primary" icon={<ExternalLink size={13} />} onClick={() => window.open(deployment.url, '_blank', 'noopener')}>
                        Open preview
                    </Button>
                }
                overflow={[
                    { key: 'rebuild', label: 'Rebuild from latest template', icon: <RotateCw size={13} />, onSelect: () => setConfirm('rebuild'), testId: 'rebuild-deployment' },
                    { key: 'destroy', label: 'Destroy', icon: <Trash2 size={13} />, danger: true, dividerAbove: true, onSelect: () => setConfirm('destroy'), testId: 'destroy-deployment' },
                ]}
            />

            <div className="min-h-0 flex-1 overflow-auto">
                <div className="mx-auto max-w-[1200px] px-4 py-6 sm:px-8">
                    <div className="mb-6 flex flex-wrap items-start justify-between gap-6">
                        <div className="min-w-0">
                            <div className="flex items-center gap-2.5">
                                <h1 className="truncate font-mono text-[18px] font-semibold tracking-tight">{deployment.branch}</h1>
                                <StatusPill tone={deployment.tone} label={deployment.statusLabel} />
                            </div>
                            <p className="mt-1 text-[12.5px] text-muted">
                                {deployment.repoSlug}
                            </p>
                        </div>
                        <dl className="grid grid-cols-[auto_auto] gap-x-6 gap-y-1 text-[12px]">
                            <dt className="text-faint">Current commit</dt>
                            <dd className="font-mono">
                                {deployment.commit && deployment.commitUrl ? (
                                    <a href={deployment.commitUrl} target="_blank" rel="noopener noreferrer" className="text-accent-text hover:underline">
                                        {deployment.commit}
                                    </a>
                                ) : (
                                    '—'
                                )}
                            </dd>
                            <dt className="text-faint">Template version</dt>
                            <dd>
                                v{deployment.templateVersion}{' '}
                                {deployment.repoTemplateVersion !== deployment.templateVersion && <Badge tone="warn">repo current: v{deployment.repoTemplateVersion}</Badge>}
                            </dd>
                            <dt className="text-faint">Last accessed</dt>
                            <dd>{deployment.lastAccessedAgo ?? 'Never'}</dd>
                        </dl>
                    </div>

                    {deployment.failure && (
                        <div className="mb-6 flex items-start gap-3 rounded-card border border-fail/30 bg-fail-soft/40 px-4 py-3 text-[12.5px]">
                            <AlertTriangle size={15} className="mt-0.5 text-fail" />
                            <div>
                                <div className="font-medium text-fail">{deployment.failure}</div>
                            </div>
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                        <ActivityLog logs={logs} />

                        <div className="flex flex-col gap-4">
                            <HibernationCard deploymentId={deployment.id} hibernation={hibernation} />
                            <ManifestCard repoSlug={deployment.repoSlug} manifest={manifest} />
                            <ShareLinkCard deploymentId={deployment.id} shareLink={shareLink} mintedUrl={mintedUrl} />
                        </div>
                    </div>
                </div>
            </div>

            <ConfirmDialog
                open={confirm === 'rebuild'}
                onOpenChange={() => setConfirm(null)}
                destructive={false}
                title="Rebuild from latest template"
                body={`Rebuilds this preview from template v${deployment.repoTemplateVersion}. Container data will be lost.`}
                confirmLabel="Rebuild"
                busy={busy}
                onConfirm={() => {
                    rebuild();
                    setConfirm(null);
                }}
            />
            <ConfirmDialog
                open={confirm === 'destroy'}
                onOpenChange={() => setConfirm(null)}
                title="Destroy this deployment"
                body={`Removes the container and the hostname ${deployment.hostname}. A new push to the branch creates a fresh preview.`}
                confirmLabel="Destroy"
                busy={busy}
                onConfirm={() => {
                    destroy();
                    setConfirm(null);
                }}
            />
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
