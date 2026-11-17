import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import type { PageProps } from '@/types/shared';
import type { YakConfig } from '@/types/repositories';
import type { RepositorySettingsHeader } from '@/types/repositorySettings';

export default function Danger({ header, yakConfig }: PageProps<{ header: RepositorySettingsHeader; yakConfig?: YakConfig }>) {
    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="danger">
            <p className="text-muted">Danger zone settings</p>
        </RepositorySettingsLayout>
    );
}

Danger.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
