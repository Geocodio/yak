import { Link, router } from '@inertiajs/react';
import { levelColor } from '@/components/approvals/levels';
import type { RepositorySettingsHeader, SettingsSectionSlug } from '@/types/repositorySettings';

type Props = {
    header: RepositorySettingsHeader;
    active: SettingsSectionSlug;
    dirtySections: SettingsSectionSlug[];
};

/** Left column from `lg`, a native select below it. */
export function SectionNav({ header, active, dirtySections }: Props) {
    const { level } = header.approval;

    return (
        <>
            <select
                aria-label="Settings section"
                data-testid="section-select"
                value={active}
                onChange={(event) => router.visit(header.sections.find((section) => section.slug === event.target.value)?.url ?? '#')}
                className="h-9 w-full rounded-control border border-hair bg-panel px-3 text-[14px] text-text lg:hidden"
            >
                {header.sections.map((section) => (
                    <option key={section.slug} value={section.slug}>
                        {section.label}{dirtySections.includes(section.slug) ? ' (unsaved)' : ''}
                    </option>
                ))}
            </select>
            <nav aria-label="Settings sections" data-testid="section-nav" className="hidden w-[220px] shrink-0 flex-col gap-0.5 lg:flex">
                {header.sections.map((section) => {
                    const isActive = section.slug === active;

                    return (
                        <Link
                            key={section.slug}
                            href={section.url}
                            aria-current={isActive ? 'page' : undefined}
                            data-testid={`section-nav-${section.slug}`}
                            className={`flex items-center gap-2 rounded-control px-3 py-2 text-[14px] ${isActive ? 'bg-panel-2 font-semibold text-text' : 'text-muted hover:bg-panel-2 hover:text-text'}`}
                        >
                            <span className="flex-1 text-left">{section.label}</span>
                            {section.slug === 'approvals' && (
                                <span
                                    className="rounded-full px-2 py-0.5 font-mono text-[10px] font-semibold"
                                    style={{ background: levelColor(level, 'tint'), color: levelColor(level, 'text') }}
                                >
                                    DEFCON {level}
                                </span>
                            )}
                            {dirtySections.includes(section.slug) && <span aria-label="Unsaved changes" className="size-2 rounded-full bg-warn" />}
                        </Link>
                    );
                })}
            </nav>
        </>
    );
}
