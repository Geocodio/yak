import { BarChart3, Code2, DollarSign, Eye, Heart, ListChecks, MessageSquare, Rocket } from 'lucide-react';
import type { NavItem } from '@/components/Sidebar';
import { SETTINGS_SECTIONS } from '@/components/shell/settingsSections';
import { analytics, costs, deployments, health, observations, prReviews, repos, tasks } from '@/routes';

export type RailSection = 'threads' | 'operations' | 'insights' | 'settings';

export const RAIL_SECTIONS: RailSection[] = ['threads', 'operations', 'insights', 'settings'];

export const OPERATIONS_ITEMS: NavItem[] = [
    { label: 'Task log', icon: ListChecks, url: tasks.url() },
    { label: 'Repositories', icon: Code2, url: repos.url() },
    { label: 'Deployments', icon: Rocket, url: deployments.url() },
    { label: 'PR reviews', icon: MessageSquare, url: prReviews.url() },
    { label: 'Observations', icon: Eye, url: observations.url() },
];

export const INSIGHTS_ITEMS: NavItem[] = [
    { label: 'Analytics', icon: BarChart3, url: analytics.url() },
    { label: 'Costs', icon: DollarSign, url: costs.url() },
    { label: 'Health', icon: Heart, url: health.url() },
];

const SETTINGS_PREFIXES = ['/settings', '/prompts', '/skills', '/mcp', '/channels', '/system'];

function startsWithPath(path: string, prefix: string): boolean {
    return path === prefix || path.startsWith(`${prefix}/`);
}

/** The rail section a URL belongs to; anything unrecognised is the thread list. */
export function sectionForUrl(url: string): RailSection {
    const path = url.split('?')[0].split('#')[0];

    if (SETTINGS_PREFIXES.some((prefix) => startsWithPath(path, prefix))) {
        return 'settings';
    }
    if (OPERATIONS_ITEMS.some((item) => startsWithPath(path, item.url))) {
        return 'operations';
    }
    if (INSIGHTS_ITEMS.some((item) => startsWithPath(path, item.url))) {
        return 'insights';
    }

    return 'threads';
}

/** Settings pages only admins can open; the nav hides them for members. */
export const ADMIN_ONLY_URL = '/settings/people';

export const SETTINGS_PANEL_GROUPS: { title: string; items: NavItem[] }[] = SETTINGS_SECTIONS.map((section) => ({
    title: section.title,
    items: section.items.map((item) => ({ label: item.label, icon: () => item.icon, url: item.href })),
}));

type SectionCopy = { title: string; description: string; items?: NavItem[]; groups?: { title: string; items: NavItem[] }[] };

export const SECTION_COPY: Record<Exclude<RailSection, 'threads'>, SectionCopy> = {
    operations: { title: 'Operations', description: 'The repositories, previews and reviews Yak works across.', items: OPERATIONS_ITEMS },
    insights: { title: 'Insights', description: 'How Yak is performing, what it costs and whether it is healthy.', items: INSIGHTS_ITEMS },
    settings: { title: 'Settings', description: '', groups: SETTINGS_PANEL_GROUPS },
};
