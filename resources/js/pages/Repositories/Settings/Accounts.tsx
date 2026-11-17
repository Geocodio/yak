import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import type { PageProps } from '@/types/shared';
import type { YakConfig } from '@/types/repositories';
import type { RepositorySettingsHeader } from '@/types/repositorySettings';

export default function Accounts({ header, yakConfig }: PageProps<{ header: RepositorySettingsHeader; yakConfig?: YakConfig }>) {
    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="accounts">
            <p className="text-muted">Accounts settings</p>
        </RepositorySettingsLayout>
    );
}

Accounts.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
