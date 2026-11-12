import { Head, router, useForm } from '@inertiajs/react';
import { PageHeader } from '@geocodio/console-ui';
import { useState, type ReactNode } from 'react';
import { LaunchControl } from '@/components/approvals/LaunchControl';
import { LevelHero } from '@/components/approvals/LevelHero';
import { LevelLever } from '@/components/approvals/LevelLever';
import { OrderBar } from '@/components/approvals/OrderBar';
import { ProtectedAssets } from '@/components/approvals/ProtectedAssets';
import { ShadowLog } from '@/components/approvals/ShadowLog';
import { StandDown } from '@/components/approvals/StandDown';
import { AppLayout } from '@/layouts/AppLayout';
import type { PageProps } from '@/types/shared';
import type { ApprovalLevel, ApprovalMode, ApprovalsPage } from '@/types/approvals';

export default function Approvals({ repository, approval, concerns, shadow, github }: PageProps<ApprovalsPage>) {
    const form = useForm<{ level: ApprovalLevel; mode: ApprovalMode }>({ level: approval.level, mode: approval.mode });
    const [isStandingDown, setIsStandingDown] = useState(false);
    const live = { level: approval.level, mode: approval.mode };
    const draft = form.data;
    const isDirty = draft.level !== live.level || draft.mode !== live.mode;
    const isArmed = draft.level > 1 && draft.mode === 'enforce';

    const pickLevel = (level: ApprovalLevel) => form.setData({ level, mode: level === 1 ? 'shadow' : draft.mode });
    const setArmed = (armed: boolean) => form.setData('mode', armed ? 'enforce' : 'shadow');
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
        <>
            <Head title={`Review approvals, ${repository.slug}`} />
            <PageHeader crumbs={['Repositories', repository.slug, 'Review approvals']} />
            <div className="mx-auto flex w-full max-w-[1200px] flex-col gap-5 px-6 py-6">
                <header>
                    <h1 className="text-[20px] font-semibold">How much may Yak approve on its own?</h1>
                    <p className="mt-1 max-w-[720px] text-[14px] text-muted">
                        Yak reviews every pull request. This lever sets which clean ones it may also approve, so people only review critical code. A human always merges.
                    </p>
                </header>
                <LevelHero approval={approval} draft={draft} isDirty={isDirty} />
                <div className="flex flex-wrap items-start gap-5">
                    <LevelLever liveLevel={live.level} draftLevel={draft.level} isArmed={isArmed} onPick={pickLevel} />
                    <LaunchControl repository={repository} github={github} concerns={concerns} shadow={shadow} draftLevel={draft.level} isArmed={isArmed} onArmedChange={setArmed}>
                        <StandDown isAlreadyDown={live.level === 1} isPending={isStandingDown} onStandDown={standDown} />
                    </LaunchControl>
                </div>
                <ShadowLog shadow={shadow} liveLevel={live.level} />
                <ProtectedAssets repository={repository} concerns={concerns} github={github} />
                {isDirty && (
                    <OrderBar live={live} draft={draft} slug={repository.slug} isSaving={form.processing} error={form.errors.mode ?? form.errors.level} onDiscard={() => { form.setData(live); form.clearErrors(); }} onTransmit={save} />
                )}
            </div>
        </>
    );
}

Approvals.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
