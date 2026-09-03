export type InstalledSkillRow = {
    key: string;
    name: string;
    marketplace: string;
    version: string;
    enabled: boolean;
    installedAgo: string;
    lastUpdatedAgo: string | null;
};

export type BundledSkillRow = {
    name: string;
    description: string;
};

export type AvailableSkillRow = {
    key: string;
    name: string;
    description: string;
    marketplace: string;
    category: string | null;
    link: string | null;
};

export type MarketplaceRow = {
    name: string;
    source: string;
    lastUpdatedAgo: string | null;
};

export type SkillsFilterValue = 'all' | 'installed' | 'bundled' | 'available';

export type SkillsFilters = {
    search: string;
    filter: SkillsFilterValue;
};
