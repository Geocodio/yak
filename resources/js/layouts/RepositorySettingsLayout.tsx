import { Deferred, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@geocodio/console-ui';
import { useMemo, type ReactNode } from 'react';
import { useCommandPaletteActions } from '@/commandPalette/useCommandPaletteActions';
import type { PaletteAction } from '@/commandPalette/types';
import { openNewThread } from '@/components/Sidebar';
import { show as showRiskProfile } from '@/routes/repos/risk-profile';
import { SectionNav } from '@/components/repositories/settings/SectionNav';
import { SettingsHeader } from '@/components/repositories/settings/SettingsHeader';
import { YakConfigStrip, YakConfigStripSkeleton } from '@/components/repositories/YakConfigStrip';
import { useDocsUrl } from '@/lib/docsUrl';
import type { YakConfig } from '@/types/repositories';
import type { RepositorySettingsHeader, SettingsSectionSlug } from '@/types/repositorySettings';

type Props = {
    header: RepositorySettingsHeader;
    yakConfig?: YakConfig;
    active: SettingsSectionSlug;
    dirtySections?: SettingsSectionSlug[];
    /** Name shown in the `.yak/` strip; falls back to wording that needs no name. */
    branch?: string;
    children: ReactNode;
};

/**
 * Frame shared by every repository settings section: crumbs, header pills,
 * the `.yak/` strip and the section nav. A section page renders its own body
 * as `children`. Pages keep `AppLayout` as their persistent layout
 * (`Page.layout = (page) => <AppLayout>{page}</AppLayout>`), so the shell does
 * not remount between sections.
 */
export function RepositorySettingsLayout({ header, yakConfig, active, dirtySections = [], branch = 'the default branch', children }: Props) {
    const sectionLabel = header.sections.find((section) => section.slug === active)?.label ?? '';
    const docsBaseUrl = useDocsUrl('');
    const { slug, githubUrl, prReviewEnabled, sections } = header;
    const paletteActions = useMemo<PaletteAction[]>(
        () => [
            { id: 'this-repository-new-thread', label: 'New thread in this repository', keywords: ['create', 'start', 'spawn'], run: () => openNewThread({ repository: slug }) },
            ...(githubUrl === null ? [] : [{ id: 'this-repository-open-github', label: 'Open on GitHub', keywords: ['repo', 'code', 'source'], hint: 'new window', href: githubUrl, run: () => window.open(githubUrl, '_blank', 'noopener') }]),
            ...(prReviewEnabled ? [{ id: 'this-repository-risk-profile', label: 'Open the risk profile', keywords: ['risk', 'review', 'approval'], href: showRiskProfile.url(slug) }] : []),
            { id: 'this-repository-set-approval-level', label: 'Set approval level', keywords: ['defcon', 'auto-approval', 'approve'], href: sections.find((section) => section.slug === 'approvals')?.url },
            ...sections
                .filter((section) => section.slug !== active)
                .map((section) => ({ id: `this-repository-go-${section.slug}`, label: `Go to ${section.label}`, keywords: ['settings', 'section'], href: section.url })),
        ],
        [slug, githubUrl, prReviewEnabled, sections, active],
    );
    useCommandPaletteActions('This repository', paletteActions);
    const configUrl = (usePage().props as { docsLinks?: { config?: string } }).docsLinks?.config ?? docsBaseUrl;

    return (
        <>
            <Head title={`${sectionLabel}, ${header.slug}`} />
            <PageHeader crumbs={['Repositories', header.slug, sectionLabel]} />
            <div className="min-h-0 flex-1 overflow-auto">
                <div className="mx-auto w-full max-w-[1200px] px-6 py-6">
                    <SettingsHeader header={header} />
                    <Deferred data="yakConfig" fallback={<YakConfigStripSkeleton branch={branch} />}>
                        {yakConfig ? <YakConfigStrip config={yakConfig} slug={header.slug} branch={branch} setupDone={yakConfig.setupStatus === 'ready'} configUrl={configUrl} /> : null}
                    </Deferred>
                    <div className="flex flex-col gap-6 lg:flex-row">
                        <SectionNav header={header} active={active} dirtySections={dirtySections} />
                        <main className="min-w-0 flex-1" data-testid="settings-main">{children}</main>
                    </div>
                </div>
            </div>
        </>
    );
}
