import type { OperationsPanelData, ThreadListData } from './shell';

export type SharedProps = {
    auth: { user: { id: number; name: string; email: string; initials: string } | null };
    flash: { success?: string | null; error?: string | null; id?: string | null; startedThreadId?: number | null; connectedAccount?: { name: string; kind: string; defaultModel: string | null; expiresAt: string | null } | null };
    nav: { activeTaskCount: number; needsYouCount: number };
    threadList: ThreadListData | null;
    operationsPanel: OperationsPanelData | null;
    docs: { baseUrl: string };
    emailToYakAddress: string | null;
    attachmentLimits: { maxFiles: number; maxFileBytes: number };
};

export type PageProps<T = Record<string, unknown>> = SharedProps & T;
