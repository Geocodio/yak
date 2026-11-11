import { cn } from '@geocodio/console-ui';
import { usePage } from '@inertiajs/react';
import { RAIL_SECTIONS, type RailSection } from '@/components/shell/sections';
import type { SharedProps } from '@/types/shared';

const LABELS: Record<RailSection, string> = {
    threads: 'Threads',
    operations: 'Operations',
    insights: 'Insights',
    settings: 'Settings',
};

/** The four-tab section switcher at the top of the phone sheet; the Threads tab carries a dot while something needs the viewer. */
export function MobileSectionSwitcher({ active, onSelect }: { active: RailSection; onSelect: (section: RailSection) => void }) {
    const { props } = usePage<SharedProps>();

    return (
        <div role="tablist" aria-label="Sections" className="mx-2 mb-2 flex rounded-control bg-panel-2 p-0.5" data-testid="mobile-section-switcher">
            {RAIL_SECTIONS.map((section) => (
                <button
                    key={section}
                    type="button"
                    role="tab"
                    aria-selected={active === section}
                    onClick={() => onSelect(section)}
                    data-testid={`mobile-section-${section}`}
                    className={cn(
                        'relative flex h-9 flex-1 items-center justify-center rounded-control text-[12.5px] text-muted',
                        active === section && 'bg-panel font-medium text-body shadow-card',
                    )}
                >
                    {LABELS[section]}
                    {section === 'threads' && props.nav.needsYouCount > 0 ? (
                        <span className="absolute right-1.5 top-1.5 h-1.5 w-1.5 rounded-pill bg-brand" data-testid="mobile-threads-dot" />
                    ) : null}
                </button>
            ))}
        </div>
    );
}
