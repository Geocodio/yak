import { Deferred, Link, useForm } from '@inertiajs/react';
import { Button, Checkbox, ConfirmDialog, Skeleton } from '@geocodio/console-ui';
import { useState, type ReactNode } from 'react';
import { ConfigSourceBadge, LockedField } from '@/components/repositories/ConfigSourceBadge';
import { CoOwnerGateStatus, CoOwnerGateStatusSkeleton } from '@/components/repositories/CoOwnerGateStatus';
import { PathExcludes } from '@/components/repositories/PathExcludes';
import { ReviewApprovalSettings } from '@/components/repositories/ReviewApprovalSettings';
import { InstantToggle } from '@/components/repositories/settings/InstantToggle';
import { SectionSaveBar } from '@/components/repositories/settings/SectionSaveBar';
import { SectionIntro, SettingRow, SettingsCard } from '@/components/repositories/settings/SettingsCard';
import { AppLayout } from '@/layouts/AppLayout';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import { useLeaveGuard } from '@/lib/useLeaveGuard';
import { useRouterAction } from '@/lib/useRouterAction';
import { show as showPrompt } from '@/routes/prompts';
import repos from '@/routes/repos';
import type { PageProps } from '@/types/shared';
import type { CoOwnerGateStatus as CoOwnerGateStatusData, RepositoryDocsLinks, RepositoryOptions, YakConfig } from '@/types/repositories';
import type { RepositorySettingsHeader, ReviewRepository } from '@/types/repositorySettings';

type Props = PageProps<{
    header: RepositorySettingsHeader;
    repository: ReviewRepository;
    options: RepositoryOptions;
    docsLinks: RepositoryDocsLinks;
    coOwnerGate?: CoOwnerGateStatusData | null;
    yakConfig?: YakConfig;
}>;

export default function Review({ header, repository, options, docsLinks, coOwnerGate, yakConfig }: Props) {
    const action = useRouterAction();
    const fileValues = yakConfig?.values;
    const isReadingConfig = yakConfig === undefined;
    const isPrReviewLocked = fileValues?.prReviewEnabled != null;
    const isPrReviewOn = isPrReviewLocked ? (fileValues?.prReviewEnabled as boolean) : repository.prReviewEnabled;
    const isPathExcludesLocked = fileValues?.pathExcludes != null;
    const showsOldRiskProfile = isPrReviewOn && !isReadingConfig && !fileValues?.hasConcernsFile;
    const lockedCount = [isPrReviewLocked, isPathExcludesLocked].filter(Boolean).length;

    const [isAskingOpenPrs, setIsAskingOpenPrs] = useState(false);
    const [reviewsOpenPrs, setReviewsOpenPrs] = useState(true);
    const [commitTurnOn, setCommitTurnOn] = useState<((extra?: Record<string, boolean>) => void) | null>(null);

    const form = useForm({
        path_excludes: repository.pathExcludes,
        pr_review_policy: repository.reviewPolicy,
    });
    const { dialog } = useLeaveGuard(form.isDirty);

    const save = () => {
        form.transform((data) => ({
            ...(isPathExcludesLocked ? {} : { path_excludes: data.path_excludes }),
            ...(showsOldRiskProfile ? { pr_review_policy: data.pr_review_policy } : {}),
        }));
        form.patch(repos.update.url(repository.slug), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    const discard = () => {
        form.reset();
        form.clearErrors();
    };

    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="review" dirtySections={form.isDirty ? ['review'] : []} branch={repository.defaultBranch}>
            <SectionIntro
                title="PR review"
                description={
                    lockedCount > 0
                        ? `${lockedCount} of 2 settings here come from .yak files. Those change through a pull request.`
                        : 'Yak reviews pull requests opened by people and other bots.'
                }
            />

            <SettingsCard docsHref={docsLinks.prReview}>
                {isReadingConfig ? (
                    <Skeleton className="h-14 w-full rounded-control" />
                ) : (
                    <InstantToggle
                        slug={repository.slug}
                        field="pr_review_enabled"
                        label="Review pull requests"
                        description={isPrReviewLocked ? 'Set by review.enabled in the file.' : 'Have Yak review every open, non-draft pull request on this repo.'}
                        checked={isPrReviewOn}
                        disabled={isPrReviewLocked}
                        badge={yakConfig && isPrReviewLocked ? <ConfigSourceBadge field="prReviewEnabled" config={yakConfig} fileName="config.yml" /> : undefined}
                        intercept={(next, commit) => {
                            if (!next) {
                                return false;
                            }

                            setCommitTurnOn(() => commit);
                            setIsAskingOpenPrs(true);

                            return true;
                        }}
                    />
                )}
                {repository.prReviewEnabled && isPrReviewOn && (
                    <SettingRow label="Review every open PR now" description="Queues a fresh review on every open, non-draft pull request.">
                        <Button
                            pending={action.isPending('review-open-prs')}
                            pendingLabel="Queueing reviews…"
                            onClick={() => action.run('review-open-prs', 'post', repos.reviewOpenPrs.url(repository.slug))}
                        >
                            Review open PRs
                        </Button>
                    </SettingRow>
                )}
            </SettingsCard>

            {isReadingConfig && isPrReviewOn ? (
                <Skeleton className="mb-4 h-24 w-full rounded-card" />
            ) : isPrReviewOn && yakConfig && isPathExcludesLocked ? (
                <SettingsCard>
                    <LockedField label="PR review path filters" field="pathExcludes" config={yakConfig} fileName="config.yml" mono propose>
                        {fileValues?.pathExcludes?.join('\n') || 'None'}
                    </LockedField>
                </SettingsCard>
            ) : isPrReviewOn ? (
                <div className="mb-4">
                    <PathExcludes value={form.data.path_excludes} defaults={options.defaultPathExcludes} onChange={(value) => form.setData('path_excludes', value)} />
                </div>
            ) : null}

            <div className="mb-4">
                <Deferred data="coOwnerGate" fallback={<CoOwnerGateStatusSkeleton branch={repository.defaultBranch} />}>
                    {coOwnerGate ? <CoOwnerGateStatus status={coOwnerGate} branch={repository.defaultBranch} /> : null}
                </Deferred>
            </div>

            <SettingsCard>
                <SettingRow label="Review prompts" description="The prompts are shared by every repository.">
                    <div className="flex flex-wrap gap-3 text-[12px]">
                        <Link className="text-accent-text hover:underline" href={showPrompt.url('tasks-review')}>Edit PR review prompt</Link>
                        <Link className="text-accent-text hover:underline" href={showPrompt.url('tasks-risk-profile')}>Edit risk profile prompt</Link>
                    </div>
                </SettingRow>
            </SettingsCard>

            {isPrReviewOn && isReadingConfig && <Skeleton className="mb-4 h-48 w-full rounded-card" />}
            {showsOldRiskProfile && (
                <div className="mb-4">
                    <h3 className="text-[14px] font-semibold">Old risk profile (version 1)</h3>
                    <p className="mb-3 mt-0.5 text-[13px] text-muted">Used until the repository has a protected assets file.</p>
                    <ReviewApprovalSettings
                        value={form.data.pr_review_policy}
                        onChange={(policy) => form.setData('pr_review_policy', policy)}
                        errors={form.errors}
                        repository={repository}
                        yakConfig={yakConfig}
                    />
                </div>
            )}

            <SectionSaveBar isDirty={form.isDirty} processing={form.processing} onSave={save} onDiscard={discard} />
            {dialog}
            <ConfirmDialog
                open={isAskingOpenPrs}
                onOpenChange={setIsAskingOpenPrs}
                title="Turn on PR review?"
                destructive={false}
                confirmLabel="Turn on"
                confirmTestId="review-turn-on-confirm"
                body={
                    <div className="flex flex-col gap-3">
                        <p>Yak will review new pull requests on this repository.</p>
                        <Checkbox label="Also review the open PRs" checked={reviewsOpenPrs} onCheckedChange={setReviewsOpenPrs} data-testid="review-open-prs-checkbox" />
                    </div>
                }
                onConfirm={() => {
                    commitTurnOn?.({ apply_to_open_prs: reviewsOpenPrs });
                    setIsAskingOpenPrs(false);
                }}
            />
        </RepositorySettingsLayout>
    );
}

Review.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
