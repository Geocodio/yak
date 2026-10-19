import { useCallback, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { AreasTab } from '@/components/riskProfile/AreasTab';
import { OpenQuestions } from '@/components/riskProfile/OpenQuestions';
import { DeadAreasBanner, InvalidFileBanner, OpenQuestionsBanner, UnreachableBanner } from '@/components/riskProfile/ProfileBanners';
import { defaultAreaIndex, RepositoryMap } from '@/components/riskProfile/RepositoryMap';
import { StatTiles } from '@/components/riskProfile/StatTiles';
import type { ChangeTestResult, RiskProfilePage, RiskProfileRepository } from '@/types/riskProfile';

type Tab = 'map' | 'areas';

function tabFromHash(): Tab {
    return typeof window !== 'undefined' && window.location.hash.replace('#', '') === 'areas' ? 'areas' : 'map';
}

export function ProfileView({
    page,
    repository,
    changeTest,
    changeTestResult = null,
}: {
    page: RiskProfilePage;
    repository: RiskProfileRepository;
    changeTest?: ReactNode;
    changeTestResult?: ChangeTestResult | null;
}) {
    const [tab, setTab] = useState<Tab>(tabFromHash);
    const profile = page.profile;
    const [selectedArea, setSelectedArea] = useState<number | null>(() => defaultAreaIndex(page.profile?.areas ?? []));

    useEffect(() => {
        const onHashChange = () => setTab(tabFromHash());
        window.addEventListener('hashchange', onHashChange);

        return () => window.removeEventListener('hashchange', onHashChange);
    }, []);

    const select = useCallback((next: Tab, shouldFocus = false) => {
        setTab(next);
        if (shouldFocus) {
            document.getElementById(`risk-tab-${next}`)?.focus();
        }
        window.history.replaceState(null, '', next === 'areas' ? '#areas' : window.location.pathname + window.location.search);
    }, []);

    const jumpToQuestions = useCallback(() => {
        select('areas');
        requestAnimationFrame(() => document.getElementById('open-questions')?.scrollIntoView({ behavior: 'smooth' }));
    }, [select]);

    if (!profile) {
        return null;
    }

    const deadAreas = profile.areas.filter((area) => area.matchesNoFile).length;
    const tabs: { key: Tab; label: string; count?: number }[] = [
        { key: 'map', label: 'Map' },
        { key: 'areas', label: 'Areas', count: profile.areas.length },
    ];

    return (
        <div data-testid="risk-profile-view" className="space-y-3">
            {page.file?.error && page.file.validCommitSha && <InvalidFileBanner page={page} branch={repository.defaultBranch} />}
            {page.readState === 'unreachable' && <UnreachableBanner page={page} />}
            {profile.unknowns.length > 0 && <OpenQuestionsBanner count={profile.unknowns.length} onJump={jumpToQuestions} />}
            {deadAreas > 0 && <DeadAreasBanner count={deadAreas} branch={repository.defaultBranch} />}
            <StatTiles profile={profile} map={page.map} />
            {changeTest}
            <div
                role="tablist"
                aria-label="Risk profile views"
                onKeyDown={(event) => {
                    const last = tabs.length - 1;
                    const current = tabs.findIndex(({ key }) => key === tab);
                    const target = { ArrowRight: current === last ? 0 : current + 1, ArrowLeft: current === 0 ? last : current - 1, Home: 0, End: last }[event.key];

                    if (target !== undefined) {
                        event.preventDefault();
                        select(tabs[target].key, true);
                    }
                }}
                className="inline-flex rounded-[8px] bg-panel-2 p-0.5">
                {tabs.map(({ key, label, count }) => (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        id={`risk-tab-${key}`}
                        aria-controls={`risk-panel-${key}`}
                        tabIndex={tab === key ? 0 : -1}
                        aria-selected={tab === key}
                        data-testid={`risk-tab-${key}`}
                        onClick={() => select(key)}
                        className={`flex h-6 items-center gap-1.5 rounded-[6px] px-2.5 text-[12px] ${tab === key ? 'bg-panel font-medium text-text shadow-sm' : 'text-muted'}`}
                    >
                        {label}
                        {count !== undefined && <small className="text-[11px] text-muted">{count}</small>}
                    </button>
                ))}
            </div>
            <div key={tab} role="tabpanel" id={`risk-panel-${tab}`} aria-labelledby={`risk-tab-${tab}`} data-testid={`risk-${tab}`} className="min-h-8">
                {tab === 'areas' && (
                    <div className="space-y-4">
                        <OpenQuestions unknowns={profile.unknowns} />
                        <AreasTab page={page} changeTest={changeTestResult} />
                    </div>
                )}
                {tab === 'map' && (
                    <RepositoryMap
                        map={page.map}
                        areas={profile.areas}
                        pins={changeTestResult?.files ?? []}
                        pinLabel={changeTestResult ? (changeTestResult.kind === 'pr' ? `PR ${changeTestResult.label}` : changeTestResult.label) : null}
                        selectedArea={profile.areas.some((area) => area.index === selectedArea) ? selectedArea : defaultAreaIndex(profile.areas)}
                        commitSha={page.commitSha}
                        onSelectArea={setSelectedArea}
                        onOpenAreas={() => select('areas')}
                    />
                )}
            </div>
        </div>
    );
}
