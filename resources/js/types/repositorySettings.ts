import type { RepositoryDetail } from '@/types/repositories';
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
    configDocsUrl: string;
    sections: { slug: SettingsSectionSlug; label: string; url: string }[];
};

/** Mirrors the section props documented on RepositorySettingsData. */
export type GeneralRepository = Pick<
    RepositoryDetail,
    | 'slug' | 'name' | 'description' | 'agentInstructions' | 'gitUrl' | 'path' | 'defaultBranch' | 'publicSiteUrl' | 'isActive' | 'isDefault'
    | 'defaultResponsibleUserId' | 'ciSystem' | 'sentryProject' | 'prReviewEnabled' | 'githubFullName' | 'githubUrl' | 'githubNameDiverged'
>;

export type ReviewRepository = Pick<RepositoryDetail, 'slug' | 'defaultBranch' | 'prReviewEnabled' | 'reviewPolicy' | 'riskProfiles' | 'riskProfileActionUrl' | 'pathExcludes'>;

export type PreviewsRepository = Pick<RepositoryDetail, 'slug' | 'deploymentsEnabled' | 'previewRepairEnabled'>;
