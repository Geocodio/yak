import { router, useForm } from '@inertiajs/react';
import { CircleAlert, ExternalLink } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Callout } from '@/components/riskProfile/RiskProfileStates';
import { approvedCountsByLevel } from '@/components/approvals/levels';
import { LaunchControl } from '@/components/approvals/LaunchControl';
import { LevelHero } from '@/components/approvals/LevelHero';
import { OpenPullRequests } from '@/components/approvals/OpenPullRequests';
import { LevelLever } from '@/components/approvals/LevelLever';
import { OrderBar } from '@/components/approvals/OrderBar';
import { ProtectedAssets } from '@/components/approvals/ProtectedAssets';
import { ShadowLog } from '@/components/approvals/ShadowLog';
import { StandDown } from '@/components/approvals/StandDown';
import { AppLayout } from '@/layouts/AppLayout';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import { useLeaveGuard } from '@/lib/useLeaveGuard';
import { useRetained } from '@/lib/useRetained';
import type { RepositorySettingsHeader } from '@/types/repositorySettings';
import type { YakConfig } from '@/types/repositories';
import type { PageProps } from '@/types/shared';
import type { ApprovalLevel, ApprovalMode, ApprovalsPage } from '@/types/approvals';

export default function Approvals({ header, yakConfig: loadedYakConfig, repository, approval, concerns, shadow, github, openPullRequests, repositoryHolds }: PageProps<ApprovalsPage & { header: RepositorySettingsHeader; yakConfig?: YakConfig }>) {
    const yakConfig = useRetained(`yak-config:${header.slug}`, loadedYakConfig);
    const form = useForm<{ level: ApprovalLevel; mode: ApprovalMode }>({ level: approval.level, mode: approval.mode });
    const [isStandingDown, setIsStandingDown] = useState(false);
    const live = { level: approval.level, mode: approval.mode };
    const draft = form.data;
    const isDirty = draft.level !== live.level || draft.mode !== live.mode;
    const { dialog } = useLeaveGuard(isDirty);
    const hasChecked = openPullRequests.checkedAt !== null || openPullRequests.rows.length > 0;
    const countsByLevel = approvedCountsByLevel(openPullRequests.rows, repositoryHolds ?? []);
    const openCounts = repositoryHolds !== undefined && hasChecked && openPullRequests.rows.length > 0 ? { byLevel: countsByLevel, total: openPullRequests.rows.length } : undefined;
    const isArmed = draft.level > 1 && draft.mode === 'enforce';

    const pickLevel = (level: ApprovalLevel) => {
        form.clearErrors();
        form.setData({ level, mode: level === 1 ? 'shadow' : draft.mode });
    };
    const setArmed = (armed: boolean) => {
        form.clearErrors();
        form.setData('mode', armed ? 'enforce' : 'shadow');
    };
    const save = () =>
        form.put(repository.updateUrl, {
            preserveScroll: true,
            onSuccess: (page) => {
                const next = (page.props as unknown as ApprovalsPage).approval;
                form.setData({ level: next.level, mode: next.mode });
                form.clearErrors();
            },
        });
    const standDown = () => {
        setIsStandingDown(true);
        router.put(repository.updateUrl, { level: 1, mode: 'shadow' }, {
            preserveScroll: true,
            onSuccess: () => form.setData({ level: 1, mode: 'shadow' }),
            onFinish: () => setIsStandingDown(false),
        });
    };

    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="approvals" dirtySections={isDirty ? ['approvals'] : []} branch={repository.defaultBranch}>
            {dialog}
            <div className="flex flex-col gap-5">
                <header>
                    <h2 className="text-[18px] font-bold">How much may Yak approve on its own?</h2>
                    <p className="mt-1 max-w-[720px] text-[14px] text-muted">
                        Yak reviews every pull request. This lever sets which clean ones it may also approve, so people only review critical code. A human always merges.
                    </p>
                </header>
                <LevelHero approval={approval} draft={draft} isDirty={isDirty} />
                {repositoryHolds !== undefined && repositoryHolds.length > 0 && (
                    <div data-testid="approvals-repository-holds">
                        <Callout tone="warn" icon={<CircleAlert size={16} aria-hidden="true" className="text-warn" />}>
                            <p className="font-semibold">Blocks every PR</p>
                            <ul className="list-disc pl-[18px]">
                                {repositoryHolds.map((hold) => <li key={hold}>{hold}</li>)}
                            </ul>
                            {concerns.state !== 'ready' && github?.concernsPullRequest && (
                                <a href={github.concernsPullRequest.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 font-semibold hover:underline">
                                    Review PR #{github.concernsPullRequest.number} <ExternalLink size={13} aria-hidden="true" />
                                </a>
                            )}
                        </Callout>
                    </div>
                )}
                <div className="flex flex-wrap items-start gap-5">
                    <LevelLever liveLevel={live.level} draftLevel={draft.level} isArmed={isArmed} openCounts={openCounts} onPick={pickLevel} />
                    <LaunchControl repository={repository} github={github} concerns={concerns} shadow={shadow} draftLevel={draft.level} isArmed={isArmed} onArmedChange={setArmed}>
                        <StandDown isAlreadyDown={live.level === 1} isPending={isStandingDown} isBusy={form.processing} onStandDown={standDown} />
                    </LaunchControl>
                </div>
                {repositoryHolds === undefined ? (
                    <p data-testid="open-prs-loading" className="text-[14px] text-muted">Reading the repository-wide rules from GitHub.</p>
                ) : (
                    <OpenPullRequests repository={repository} data={openPullRequests} repositoryHolds={repositoryHolds} level={draft.level} />
                )}
                <ShadowLog shadow={shadow} liveLevel={live.level} />
                <ProtectedAssets repository={repository} concerns={concerns} github={github} />
                {isDirty && (
                    <OrderBar live={live} draft={draft} slug={repository.slug} isSaving={form.processing || isStandingDown} approvedDelta={openCounts ? countsByLevel[draft.level - 1] - countsByLevel[live.level - 1] : undefined} error={form.errors.mode ?? form.errors.level} onDiscard={() => { form.setData(live); form.clearErrors(); }} onTransmit={save} />
                )}
            </div>
        </RepositorySettingsLayout>
    );
}

Approvals.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
