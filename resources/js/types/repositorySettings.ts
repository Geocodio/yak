export type ApprovalHeaderMode = 'off' | 'shadow' | 'enforce';

export type SettingsSectionSlug = 'general' | 'review' | 'approvals' | 'previews' | 'sandbox' | 'accounts' | 'danger';

/** Mirrors RepositorySettingsData::header(). */
export type RepositorySettingsHeader = {
    slug: string;
    name: string;
    githubUrl: string | null;
    isActive: boolean;
    prReviewEnabled: boolean;
    deploymentsEnabled: boolean;
    approval: { level: 1 | 2 | 3 | 4 | 5; mode: ApprovalHeaderMode };
    sections: { slug: SettingsSectionSlug; label: string; url: string }[];
};
