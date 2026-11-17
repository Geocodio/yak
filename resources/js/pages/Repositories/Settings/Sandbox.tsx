import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import type { PageProps } from '@/types/shared';
import type { YakConfig } from '@/types/repositories';
import type { RepositorySettingsHeader } from '@/types/repositorySettings';

export default function Sandbox({ header, yakConfig }: PageProps<{ header: RepositorySettingsHeader; yakConfig?: YakConfig }>) {
    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="sandbox">
            <p className="text-muted">Sandbox settings</p>
        </RepositorySettingsLayout>
    );
}

Sandbox.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
