import { Head, router, useForm } from '@inertiajs/react';
import { Button, ConfirmDialog, Menu, TextInput } from '@geocodio/console-ui';
import { ChevronDown, Loader2, Plus, RotateCw } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { SkillCard } from '@/components/skills/SkillCard';
import { InstallFromUrlDialog } from '@/components/skills/InstallFromUrlDialog';
import { MarketplaceList } from '@/components/skills/MarketplaceList';
import { skills } from '@/routes';
import { destroy, install as installSkill, update, upgrade } from '@/routes/skills';
import { refresh } from '@/routes/marketplaces';
import type { PageProps } from '@/types/shared';
import type { AvailableSkillRow, BundledSkillRow, InstalledSkillRow, MarketplaceRow, SkillsFilters, SkillsFilterValue } from '@/types/skills';

type Props = PageProps<{
    installed: InstalledSkillRow[];
    bundled: BundledSkillRow[];
    available: AvailableSkillRow[];
    availableTotal: number;
    marketplaces: MarketplaceRow[];
    filters: SkillsFilters;
}>;

const FILTER_OPTIONS: { value: SkillsFilterValue; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'installed', label: 'Installed' },
    { value: 'bundled', label: 'Bundled' },
    { value: 'available', label: 'Available' },
];

export default function Index({ installed, bundled, available, availableTotal, marketplaces: marketplaceRows, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [searching, setSearching] = useState(false);
    const [showInstallFromUrl, setShowInstallFromUrl] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [togglingKey, setTogglingKey] = useState<string | null>(null);
    const [updatingKey, setUpdatingKey] = useState<string | null>(null);
    const [installingKey, setInstallingKey] = useState<string | null>(null);
    const [pendingUninstall, setPendingUninstall] = useState<InstalledSkillRow | null>(null);
    const [uninstalling, setUninstalling] = useState(false);

    const navigate = (next: Partial<SkillsFilters>) => {
        router.get(skills.url(), { search, filter: filters.filter, ...next }, { preserveState: true, replace: true });
    };

    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }

        setSearching(true);

        debounceRef.current = setTimeout(() => {
            router.get(
                skills.url(),
                { search, filter: filters.filter },
                { preserveState: true, replace: true, onFinish: () => setSearching(false) },
            );
        }, 200);

        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // The effect above returns early when the box already matches the applied
    // filter, so reset the flag here rather than leaving a spinner running.
    useEffect(() => {
        if (search === filters.search) {
            setSearching(false);
        }
    }, [search, filters.search]);

    const toggle = (skill: InstalledSkillRow, enabled: boolean) => {
        setTogglingKey(skill.key);
        router.patch(
            update.url(skill.key),
            { enabled },
            { preserveScroll: true, onFinish: () => setTogglingKey(null) },
        );
    };

    const updatePlugin = (skill: InstalledSkillRow) => {
        setUpdatingKey(skill.key);
        router.post(upgrade.url(skill.key), {}, { preserveScroll: true, onFinish: () => setUpdatingKey(null) });
    };

    const uninstall = () => {
        if (!pendingUninstall) {
            return;
        }

        setUninstalling(true);
        router.delete(destroy.url(pendingUninstall.key), {
            preserveScroll: true,
            onFinish: () => {
                setUninstalling(false);
                setPendingUninstall(null);
            },
        });
    };

    const install = (skill: AvailableSkillRow) => {
        setInstallingKey(skill.key);
        router.post(
            installSkill.url(),
            { name: skill.name, marketplace: skill.marketplace },
            { preserveScroll: true, onFinish: () => setInstallingKey(null) },
        );
    };

    const refreshMarketplaces = () => {
        setRefreshing(true);
        router.post(refresh.url(), {}, { preserveScroll: true, onFinish: () => setRefreshing(false) });
    };

    const selectedFilter = FILTER_OPTIONS.find((o) => o.value === filters.filter) ?? FILTER_OPTIONS[0];
    const showInstalled = filters.filter === 'all' || filters.filter === 'installed';
    const showBundled = filters.filter === 'all' || filters.filter === 'bundled';
    const showAvailable = filters.filter === 'all' || filters.filter === 'available';
    const showMarketplaces = filters.filter === 'all';

    return (
        <>
            <Head title="Skills" />
            <PageHeader
                crumbs={['Skills']}
                actions={
                    <>
                        <Button variant="secondary" icon={<RotateCw size={13} />} pending={refreshing} pendingLabel="Refreshing…" onClick={refreshMarketplaces} data-testid="refresh-marketplaces">
                            Refresh marketplaces
                        </Button>
                        <Button variant="primary" icon={<Plus size={13} />} onClick={() => setShowInstallFromUrl(true)} data-testid="open-install-from-url">
                            Install from URL
                        </Button>
                    </>
                }
            >
                <div className="ml-4 flex items-center gap-2">
                    <div className="relative">
                        <TextInput
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search plugins…"
                            className="w-56"
                            data-testid="skills-search"
                        />
                        {searching && (
                            <Loader2
                                size={13}
                                className="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 animate-spin text-faint"
                                data-testid="skills-search-spinner"
                            />
                        )}
                    </div>
                    <Menu
                        trigger={
                            <span className="flex items-center gap-1.5 text-[12px]">
                                <span className="text-body">{selectedFilter.label}</span>
                                <ChevronDown size={12} className="text-faint" />
                            </span>
                        }
                        className="h-7 rounded-pill px-2.5"
                        items={FILTER_OPTIONS.map((option) => ({
                            key: option.value,
                            label: option.label,
                            checked: option.value === filters.filter,
                            onSelect: () => navigate({ filter: option.value }),
                        }))}
                    />
                </div>
            </PageHeader>

            <div className="min-h-0 flex-1 overflow-auto p-5">
                {showInstalled && (
                    <Section title="Installed" meta={`${installed.length}`}>
                        {installed.length === 0 ? (
                            <p className="text-[12.5px] text-muted">No plugins installed yet.</p>
                        ) : (
                            <Grid>
                                {installed.map((skill) => (
                                    <SkillCard
                                        key={skill.key}
                                        variant="installed"
                                        skill={skill}
                                        onToggle={(enabled) => toggle(skill, enabled)}
                                        onUpdate={() => updatePlugin(skill)}
                                        onUninstall={() => setPendingUninstall(skill)}
                                        toggling={togglingKey === skill.key}
                                        updating={updatingKey === skill.key}
                                        uninstalling={uninstalling && pendingUninstall?.key === skill.key}
                                    />
                                ))}
                            </Grid>
                        )}
                    </Section>
                )}

                {showBundled && bundled.length > 0 && (
                    <Section title="Bundled" meta={`${bundled.length} · read-only`}>
                        <Grid>
                            {bundled.map((skill) => (
                                <SkillCard key={skill.name} variant="bundled" skill={skill} />
                            ))}
                        </Grid>
                    </Section>
                )}

                {showAvailable && available.length > 0 && (
                    <Section title="Available" meta={marketplaceRows.length > 0 ? `from ${marketplaceRows.map((m) => m.name).join(', ')}` : 'no marketplaces configured'}>
                        <Grid>
                            {available.map((skill) => (
                                <SkillCard
                                    key={skill.key}
                                    variant="available"
                                    skill={skill}
                                    onInstall={() => install(skill)}
                                    installing={installingKey === skill.key}
                                />
                            ))}
                        </Grid>
                        {availableTotal > available.length && (
                            <p className="mt-3 text-[12px] text-muted">
                                Showing {available.length} of {availableTotal}. Use search to narrow results.
                            </p>
                        )}
                    </Section>
                )}

                {showMarketplaces && (
                    <Section title="Marketplaces">
                        <MarketplaceList marketplaces={marketplaceRows} />
                    </Section>
                )}
            </div>

            <InstallFromUrlDialog open={showInstallFromUrl} onOpenChange={setShowInstallFromUrl} />

            <ConfirmDialog
                open={pendingUninstall !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingUninstall(null);
                    }
                }}
                title="Uninstall plugin?"
                body={`This removes ${pendingUninstall?.name} from this Yak instance.`}
                confirmLabel="Uninstall"
                busy={uninstalling}
                confirmTestId="uninstall-confirm"
                onConfirm={uninstall}
            />
        </>
    );
}

function Section({ title, meta, children }: { title: string; meta?: string; children: ReactNode }) {
    return (
        <div className="mb-8">
            <div className="mb-3 flex items-baseline gap-2">
                <h2 className="text-[12px] font-semibold uppercase tracking-wide text-faint">{title}</h2>
                {meta && <span className="text-[11px] text-faint">{meta}</span>}
            </div>
            {children}
        </div>
    );
}

function Grid({ children }: { children: ReactNode }) {
    return <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">{children}</div>;
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
