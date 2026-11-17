import type { ReactNode } from 'react';
import { ClaudeAccountSection } from '@/components/repositories/ClaudeAccountSection';
import { SectionIntro } from '@/components/repositories/settings/SettingsCard';
import { AppLayout } from '@/layouts/AppLayout';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import { useRetained } from '@/lib/useRetained';
import type { PageProps } from '@/types/shared';
import type { RepositoryClaudeAccounts, YakConfig } from '@/types/repositories';
import type { RepositorySettingsHeader } from '@/types/repositorySettings';

type Props = PageProps<{
    header: RepositorySettingsHeader;
    repository: { slug: string };
    claudeAccounts?: RepositoryClaudeAccounts;
    yakConfig?: YakConfig;
}>;

export default function Accounts({ header, repository, claudeAccounts, yakConfig: loadedYakConfig }: Props) {
    const yakConfig = useRetained(`yak-config:${header.slug}`, loadedYakConfig);
    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="accounts">
            <SectionIntro title="Accounts" description="Which Claude account tasks here run on. Each choice saves at once." />
            {claudeAccounts && <ClaudeAccountSection slug={repository.slug} data={claudeAccounts} />}
        </RepositorySettingsLayout>
    );
}

Accounts.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
