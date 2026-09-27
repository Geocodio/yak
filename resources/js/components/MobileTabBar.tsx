import { Link, usePage } from '@inertiajs/react';
import { TabBar, type TabBarItem, type TabBarLinkProps } from '@geocodio/console-ui';
import { Menu as MenuIcon, Plus } from 'lucide-react';
import { NAV_ITEMS, isActive, openMobileNav, openNewTask } from '@/components/Sidebar';
import type { SharedProps } from '@/types/shared';

const BAR_LABELS = ['Tasks', 'PR Reviews', 'Repositories'] as const;
const SHORT_LABELS: Record<(typeof BAR_LABELS)[number], string> = {
    Tasks: 'Tasks',
    'PR Reviews': 'Reviews',
    Repositories: 'Repos',
};

/**
 * Tab links load their page in the background when the bar mounts and keep
 * it for 30 seconds, then refresh it in the background for another minute,
 * so switching tabs does not wait on the server. The current tab is not
 * prefetched.
 */
function PrefetchingTabLink(props: TabBarLinkProps) {
    return <Link {...props} prefetch={props['aria-current'] === 'page' ? false : ['mount', 'click']} cacheFor={['30s', '1m']} />;
}

/**
 * The floating bar shown below `lg`: the three pages used every day, a
 * More slot that opens the navigation drawer for the rest, and the round
 * New task button. The slots come from the same `NAV_ITEMS` the sidebar
 * uses, so a renamed page or a moved route changes both at once.
 */
export function MobileTabBar() {
    const { props, url } = usePage<SharedProps>();

    const items: TabBarItem[] = BAR_LABELS.map((label) => {
        const item = NAV_ITEMS.find((candidate) => candidate.label === label);
        if (!item) {
            throw new Error(`Nav item "${label}" is missing from NAV_ITEMS`);
        }
        const Icon = item.icon;
        return {
            key: item.url,
            label: SHORT_LABELS[label],
            icon: <Icon size={22} />,
            href: item.url,
            active: isActive(url, item.url),
            badge: label === 'Tasks' ? props.nav.activeTaskCount : null,
            testId: `mobile-tab-${SHORT_LABELS[label].toLowerCase()}`,
        };
    });

    items.push({
        key: 'more',
        label: 'More',
        icon: <MenuIcon size={22} />,
        onSelect: openMobileNav,
        testId: 'mobile-nav-trigger',
    });

    return (
        <TabBar
            className="lg:hidden"
            LinkComponent={PrefetchingTabLink}
            items={items}
            action={{ label: 'New task', icon: <Plus size={24} />, onSelect: openNewTask, testId: 'tab-new-task' }}
            data-testid="mobile-tab-bar"
        />
    );
}
