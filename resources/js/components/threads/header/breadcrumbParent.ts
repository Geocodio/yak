import type { ThreadData } from '@/types/threads';

type Parent = { id: string; name: string; title: string };

/** The parent a sub-thread's header links back to, or null for a thread with no parent. */
export const breadcrumbParent = (thread: ThreadData, stackParent: Parent | null): Parent | null => (stackParent !== null && thread.parentThreadId !== null ? stackParent : null);
