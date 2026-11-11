import type { ThreadListData } from './shell';

export type SharedProps = {
    auth: { user: { id: number; name: string; email: string; initials: string } | null };
    flash: { success?: string | null; error?: string | null; id?: string | null; startedThreadId?: number | null };
    nav: { activeTaskCount: number; needsYouCount: number };
    threadList: ThreadListData | null;
    docs: { baseUrl: string };
    emailToYakAddress: string | null;
    attachmentLimits: { maxFiles: number; maxFileBytes: number };
};

export type PageProps<T = Record<string, unknown>> = SharedProps & T;
