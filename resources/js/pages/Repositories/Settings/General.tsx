import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import type { PageProps } from '@/types/shared';
import type { YakConfig } from '@/types/repositories';
import type { RepositorySettingsHeader } from '@/types/repositorySettings';

export default function General({ header, yakConfig }: PageProps<{ header: RepositorySettingsHeader; yakConfig?: YakConfig }>) {
    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="general">
            <p className="text-muted">General settings</p>
        </RepositorySettingsLayout>
    );
}

General.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
