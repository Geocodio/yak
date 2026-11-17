import { ConfirmDialog } from '@geocodio/console-ui';
import { useState, type ReactNode } from 'react';
import { DangerZone } from '@/components/repositories/DangerZone';
import { SectionIntro } from '@/components/repositories/settings/SettingsCard';
import { AppLayout } from '@/layouts/AppLayout';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import { useRouterAction } from '@/lib/useRouterAction';
import repos from '@/routes/repos';
import type { PageProps } from '@/types/shared';
import type { YakConfig } from '@/types/repositories';
import type { RepositorySettingsHeader } from '@/types/repositorySettings';

type Props = PageProps<{
    header: RepositorySettingsHeader;
    repository: { slug: string; name: string; isActive: boolean };
    canDelete: boolean;
    deleteBlockedReason: string | null;
    yakConfig?: YakConfig;
}>;

export default function Danger({ header, repository, canDelete, deleteBlockedReason, yakConfig }: Props) {
    const action = useRouterAction();
    const [isConfirmingDeactivate, setIsConfirmingDeactivate] = useState(false);
    const toggleActive = () => action.run('toggle-active', 'post', repos.toggleActive.url(repository.slug));

    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="danger">
            <SectionIntro title="Danger zone" />
            <DangerZone
                slug={repository.slug}
                isActive={repository.isActive}
                canDelete={canDelete}
                deleteBlockedReason={deleteBlockedReason}
                processing={action.isPending('delete')}
                onToggleActive={() => (repository.isActive ? setIsConfirmingDeactivate(true) : toggleActive())}
                onDelete={() => action.run('delete', 'delete', repos.destroy.url(repository.slug))}
                togglingActive={action.isPending('toggle-active')}
            />
            <ConfirmDialog
                open={isConfirmingDeactivate}
                onOpenChange={setIsConfirmingDeactivate}
                title={`Deactivate ${repository.slug}`}
                body="Yak stops taking tasks, alerts and reviews for this repository. Its settings stay."
                confirmLabel="Deactivate"
                confirmTestId="deactivate-confirm"
                onConfirm={() => {
                    toggleActive();
                    setIsConfirmingDeactivate(false);
                }}
            />
        </RepositorySettingsLayout>
    );
}

Danger.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
