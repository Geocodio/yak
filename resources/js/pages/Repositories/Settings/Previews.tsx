import { useForm } from '@inertiajs/react';
import { Button, Skeleton } from '@geocodio/console-ui';
import { RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { InstantToggle } from '@/components/repositories/settings/InstantToggle';
import { PreviewManifest, type ManifestErrors } from '@/components/repositories/settings/PreviewManifest';
import { SectionSaveBar } from '@/components/repositories/settings/SectionSaveBar';
import { SectionIntro, SettingRow, SettingsCard } from '@/components/repositories/settings/SettingsCard';
import { AppLayout } from '@/layouts/AppLayout';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import { useLeaveGuard } from '@/lib/useLeaveGuard';
import { useRetained } from '@/lib/useRetained';
import { useRouterAction } from '@/lib/useRouterAction';
import repos from '@/routes/repos';
import type { PageProps } from '@/types/shared';
import type { ManifestData, RepositoryDocsLinks, YakConfig } from '@/types/repositories';
import type { PreviewsRepository, RepositorySettingsHeader } from '@/types/repositorySettings';

type Props = PageProps<{
    header: RepositorySettingsHeader;
    repository: PreviewsRepository;
    manifest: ManifestData;
    runningPreviews: number;
    docsLinks: RepositoryDocsLinks;
    yakConfig?: YakConfig;
}>;

export default function Previews({ header, repository, manifest, runningPreviews, docsLinks, yakConfig: loadedYakConfig }: Props) {
    const yakConfig = useRetained(`yak-config:${header.slug}`, loadedYakConfig);
    const action = useRouterAction();
    const isReadingConfig = yakConfig === undefined;
    const isManifestLocked = yakConfig?.values.previewManifest != null;
    const showsManifest = repository.deploymentsEnabled;

    const form = useForm<{ manifest: ManifestData }>({ manifest });
    const { dialog } = useLeaveGuard(form.isDirty);

    const save = () => {
        form.transform((data) => ({
            manifest: {
                port: data.manifest.port,
                health_probe_path: data.manifest.healthProbePath,
                cold_start: data.manifest.coldStart,
                checkout_refresh: data.manifest.checkoutRefresh,
                wake_timeout_seconds: data.manifest.wakeTimeoutSeconds,
            },
        }));
        form.patch(repos.update.url(repository.slug), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    const discard = () => {
        form.reset();
        form.clearErrors();
    };

    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="previews" dirtySections={form.isDirty ? ['previews'] : []}>
            <SectionIntro
                title="Previews"
                description={isManifestLocked ? 'How a preview starts comes from .yak/preview.yml. Those values change through a pull request.' : 'Yak serves a preview deployment for each open PR branch. Each preview hibernates when idle and wakes on the next request.'}
            />

            <SettingsCard docsHref={docsLinks.previews}>
                <InstantToggle
                    slug={repository.slug}
                    field="deployments_enabled"
                    label="Branch deployments"
                    description="Serve a preview deployment for every open PR branch on this repo, each in its own isolated container."
                    checked={repository.deploymentsEnabled}
                />
                {repository.deploymentsEnabled && (
                    <InstantToggle
                        slug={repository.slug}
                        field="preview_repair_enabled"
                        label="Investigate failed previews"
                        description="When a preview fails, Yak looks into it and opens a pull request if the shared preview setup is broken. It tells the PR author and posts in #feed-yak."
                        checked={repository.previewRepairEnabled}
                    />
                )}
            </SettingsCard>

            {showsManifest && isReadingConfig && <Skeleton className="mb-4 h-64 w-full rounded-card" />}
            {showsManifest && !isReadingConfig && (
                <PreviewManifest
                    manifest={form.data.manifest}
                    errors={form.errors as ManifestErrors}
                    onChange={(next) => form.setData('manifest', next)}
                    yakConfig={yakConfig}
                />
            )}

            {showsManifest && (
                <SettingsCard>
                    <SettingRow
                        label="Rebuild all previews"
                        description={`${runningPreviews} ${runningPreviews === 1 ? 'preview is' : 'previews are'} running.`}
                    >
                        <Button
                            icon={<RefreshCw size={13} />}
                            pending={action.isPending('rebuild')}
                            pendingLabel="Rebuilding…"
                            onClick={() => action.run('rebuild', 'post', repos.rebuildDeployments.url(repository.slug))}
                        >
                            Rebuild all previews
                        </Button>
                    </SettingRow>
                </SettingsCard>
            )}

            <SectionSaveBar isDirty={form.isDirty && !isManifestLocked} processing={form.processing} disabled={isReadingConfig} onSave={save} onDiscard={discard} />
            {dialog}
        </RepositorySettingsLayout>
    );
}

Previews.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
