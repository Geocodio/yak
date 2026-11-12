import type { OperationsPanelData, ThreadListData } from './shell';

export type SetupProps = {
    hasOwnAccount: boolean;
    brokenAccount: { id: number; name: string } | null;
    hasDevice: boolean;
    latestDeviceName: string | null;
    needsYakAccount: boolean;
    installCommand: string;
    loginCommand: string;
    models: string[];
    appName: string;
};

export type SharedProps = {
    auth: { user: { id: number; name: string; email: string; initials: string } | null };
    flash: { success?: string | null; error?: string | null; id?: string | null; startedThreadId?: number | null; connectedAccount?: { name: string; kind: string; defaultModel: string | null; expiresAt: string | null } | null };
    nav: { activeTaskCount: number; needsYouCount: number };
    threadList: ThreadListData | null;
    operationsPanel: OperationsPanelData | null;
    setup: SetupProps | null;
    docs: { baseUrl: string };
    emailToYakAddress: string | null;
    attachmentLimits: { maxFiles: number; maxFileBytes: number };
};

export type PageProps<T = Record<string, unknown>> = SharedProps & T;
