import { Link, usePage } from '@inertiajs/react';
import { TabBar, type TabBarItem, type TabBarLinkProps } from '@geocodio/console-ui';
import { Code2, MessageSquare, Menu as MenuIcon, MessagesSquare, Plus } from 'lucide-react';
import { isActive, openMobileNav, openNewTask } from '@/components/Sidebar';
import { prReviews, repos, tasks } from '@/routes';
import type { SharedProps } from '@/types/shared';

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
 * The floating bar shown below `lg`: the three pages used every day (Tasks is labelled Threads and points at /tasks until the jobs track lands), a
 * More slot that opens the navigation drawer for the rest, and the round
 * New thread button. The slots come from the same `NAV_ITEMS` the sidebar
 * uses, so a renamed page or a moved route changes both at once.
 */
export function MobileTabBar() {
    const { props, url } = usePage<SharedProps>();

    const slots = [
        { key: 'tasks', label: 'Threads', icon: <MessagesSquare size={22} />, url: tasks.url(), badge: props.nav.activeTaskCount },
        { key: 'reviews', label: 'Reviews', icon: <MessageSquare size={22} />, url: prReviews.url(), badge: null },
        { key: 'repos', label: 'Repos', icon: <Code2 size={22} />, url: repos.url(), badge: null },
    ];
    const items: TabBarItem[] = slots.map((slot) => ({
        key: slot.url,
        label: slot.label,
        icon: slot.icon,
        href: slot.url,
        active: isActive(url, slot.url),
        badge: slot.badge,
        testId: `mobile-tab-${slot.key}`,
    }));

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
            action={{ label: 'New thread', icon: <Plus size={24} />, onSelect: openNewTask, testId: 'tab-new-task' }}
            data-testid="mobile-tab-bar"
        />
    );
}
