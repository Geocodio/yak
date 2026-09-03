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

export type McpServerRow = {
    name: string; // e.g. "linear", "plugin:figma:figma"
    displayName: string; // "linear"; for plugin servers the last segment ("figma")
    target: string; // command + args joined for stdio; URL for http/sse
    transport: 'stdio' | 'http' | 'sse' | 'unknown';
    source: 'deploy' | 'user' | 'plugin';
    pluginName: string | null; // for source 'plugin': the middle segment of plugin:<plugin>:<server>
    status: 'connected' | 'needs_auth' | 'failed' | 'pending_approval' | 'token' | 'unknown';
    // 'token' = deploy-config server (not health-checked). 'pending_approval' = CLI "⏸ Pending approval".
    detail: string | null; // failure text if the CLI printed one, else null
    canConnect: boolean; // status === 'needs_auth' (any source; plugin servers can log in too)
    canLogout: boolean; // source !== 'deploy' && status === 'connected' && transport !== 'stdio'
    canRemove: boolean; // source === 'user'
    loginCommand: string; // "yak-mcp login <name>" -- with sshHost set, the page prefixes "ssh -t root@<sshHost> "
};

export type McpLoginSessionData = {
    server: string;
    status: 'starting' | 'awaiting_redirect' | 'finishing' | 'succeeded' | 'failed' | 'expired' | 'cancelled';
    authorizationUrl: string | null; // set once status reaches awaiting_redirect
    error: string | null; // CLI error text for failed
    startedAt: string; // ISO 8601
    expiresAt: string; // ISO 8601 (startedAt + 10 min)
};
