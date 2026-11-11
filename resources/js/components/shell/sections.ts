import { Activity, BarChart3, Code2, DollarSign, Eye, Heart, Inbox, MessageSquare, Puzzle, Rocket, Server, Type, UserRound } from 'lucide-react';
import type { NavItem } from '@/components/Sidebar';
import { analytics, channels, costs, deployments, health, mcp, observations, prReviews, prompts, repos, skills, system } from '@/routes';
import { edit as profileEdit } from '@/routes/profile';

export type RailSection = 'threads' | 'operations' | 'insights' | 'settings';

export const RAIL_SECTIONS: RailSection[] = ['threads', 'operations', 'insights', 'settings'];

export const OPERATIONS_ITEMS: NavItem[] = [
    { label: 'Repositories', icon: Code2, url: repos.url() },
    { label: 'Deployments', icon: Rocket, url: deployments.url() },
    { label: 'PR Reviews', icon: MessageSquare, url: prReviews.url() },
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

export const SETTINGS_ITEMS: NavItem[] = [
    { label: 'Profile', icon: UserRound, url: profileEdit.url() },
    { label: 'Prompts', icon: Type, url: prompts.url() },
    { label: 'Skills', icon: Puzzle, url: skills.url() },
    { label: 'MCP servers', icon: Server, url: mcp.url() },
    { label: 'Channels', icon: Inbox, url: channels.url() },
    { label: 'System', icon: Activity, url: system.url() },
];

export const SECTION_COPY: Record<Exclude<RailSection, 'threads'>, { title: string; description: string; items: NavItem[] }> = {
    operations: { title: 'Operations', description: 'The repositories, previews and reviews Yak works across.', items: OPERATIONS_ITEMS },
    insights: { title: 'Insights', description: 'How Yak is performing, what it costs and whether it is healthy.', items: INSIGHTS_ITEMS },
    settings: { title: 'Settings', description: 'Your profile, prompts, skills and integrations.', items: SETTINGS_ITEMS },
};
