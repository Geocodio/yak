import { Badge, Button } from '@geocodio/console-ui';
import { BookOpen, RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { SetupHistory } from '@/components/repositories/SetupHistory';
import { SectionIntro, SettingRow, SettingsCard } from '@/components/repositories/settings/SettingsCard';
import { AppLayout } from '@/layouts/AppLayout';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import { useRouterAction } from '@/lib/useRouterAction';
import { useRetained } from '@/lib/useRetained';
import repos from '@/routes/repos';
import { tasks } from '@/routes';
import type { PageProps } from '@/types/shared';
import type { RepositoryDocsLinks, RepositoryStats, SandboxData, SetupHistoryRow, YakConfig } from '@/types/repositories';
import type { RepositorySettingsHeader } from '@/types/repositorySettings';

type Props = PageProps<{
    header: RepositorySettingsHeader;
    repository: { slug: string };
    sandbox: SandboxData;
    setupHistory: SetupHistoryRow[];
    stats: RepositoryStats;
    docsLinks: RepositoryDocsLinks;
    yakConfig?: YakConfig;
}>;

export default function Sandbox({ header, repository, sandbox, setupHistory, stats, docsLinks, yakConfig: loadedYakConfig }: Props) {
    const yakConfig = useRetained(`yak-config:${header.slug}`, loadedYakConfig);
    const action = useRouterAction();
    const isBehind = sandbox.baseVersion !== null && sandbox.baseVersion !== sandbox.latestBaseVersion;

    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="sandbox">
            <SectionIntro title="Sandbox" description="The prepared environment every task for this repository starts from." />

            <SettingsCard docsHref={docsLinks.setup}>
                <SettingRow
                    label="Template"
                    description={
                        <>
                            Snapshot <span className="font-mono">{sandbox.snapshot ?? 'none yet'}</span>.{' '}
                            {sandbox.baseVersion === null ? 'Not provisioned.' : <>yak-base <span className="font-mono">v{sandbox.baseVersion}</span>.</>}
                        </>
                    }
                    below={
                        <a href={docsLinks.rerunSetup} target="_blank" rel="noopener noreferrer" className="mt-1 inline-flex items-center gap-1 text-[12px] text-accent-text hover:underline">
                            <BookOpen size={12} /> What re-run setup does
                        </a>
                    }
                    badge={isBehind ? <Badge tone="warn">v{sandbox.latestBaseVersion} available</Badge> : undefined}
                >
                    <Button
                        icon={<RefreshCw size={13} />}
                        pending={action.isPending('rerun-setup')}
                        pendingLabel="Dispatching…"
                        onClick={() => action.run('rerun-setup', 'post', repos.rerunSetup.url(repository.slug))}
                    >
                        Run setup again
                    </Button>
                </SettingRow>
                <SettingRow label="Activity" description="Tasks and reviews for this repository.">
                    <span className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-faint" data-testid="repository-stats">
                        <span><span className="tnum text-muted">{stats.tasks}</span> tasks</span>
                        <span><span className="tnum text-muted">{stats.tasks7d}</span> in 7d</span>
                        <span><span className="tnum text-muted">{stats.reviews30d}</span> reviews in 30d</span>
                    </span>
                </SettingRow>
            </SettingsCard>

            <SettingsCard title="Setup history" description="Setup tasks prepare the dev environment and verify it works.">
                <SetupHistory rows={setupHistory} viewAllHref={setupHistory.length > 0 ? tasks.url({ query: { tab: 'setup', repo: repository.slug } }) : null} />
            </SettingsCard>
        </RepositorySettingsLayout>
    );
}

Sandbox.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
