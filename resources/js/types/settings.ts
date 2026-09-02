export type ProfileData = {
    name: string;
    email: string;
    hasUnverifiedEmail: boolean;
};

export type LinearConnectionData = {
    oauthConfigured: boolean;
    isConnected: boolean;
    workspaceName: string | null;
    workspaceId: string | null;
    actor: string | null;
    scopes: string[] | null;
    expiresAt: string | null;
    expiresIn: string | null;
    disconnectedAt: string | null;
    disconnectedAgo: string | null;
    moveIssuesToStartedState: boolean;
};

export type VideoThemeColors = {
    background: string;
    surface: string;
    ink: string;
    muted: string;
    accent: string;
    done: string;
    captionBg: string;
};

export type VideoThemeFonts = {
    display: string;
    body: string;
    mono: string;
};

export type VideoThemeData = {
    colors: VideoThemeColors;
    fonts: VideoThemeFonts;
    logoUrl: string | null;
    savedAt: string | null;
    voiceoverEnabled: boolean;
    fontFamilies: string[];
    googleFontsHref: string;
    fontPickerHref: string;
};

export type BlockKind = 'title' | 'chapter' | 'shot' | 'summary';
