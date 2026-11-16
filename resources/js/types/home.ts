import type { NewThreadPageData } from './threads';

/** Mirrors App\Http\Resources\WelcomeData. */
export type WelcomeData = {
    hasRepository: boolean;
    hasClaudeAccount: boolean;
    hasChannel: boolean;
    addRepositoryUrl: string;
    channelsUrl: string;
};

export type WelcomePageData = NewThreadPageData & { welcome: WelcomeData };
