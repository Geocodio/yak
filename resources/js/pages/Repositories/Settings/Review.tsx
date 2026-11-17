import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import type { PageProps } from '@/types/shared';
import type { YakConfig } from '@/types/repositories';
import type { RepositorySettingsHeader } from '@/types/repositorySettings';

export default function Review({ header, yakConfig }: PageProps<{ header: RepositorySettingsHeader; yakConfig?: YakConfig }>) {
    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="review">
            <p className="text-muted">PR review settings</p>
        </RepositorySettingsLayout>
    );
}

Review.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
