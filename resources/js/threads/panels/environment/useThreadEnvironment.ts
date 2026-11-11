import type { ThreadData } from '@/types/threads';
import type { ThreadEnvironment } from '@/types/thread-changes';
import { useJson } from '../useJson';

export function useThreadEnvironment(thread: ThreadData): { environment: ThreadEnvironment | null; isLoading: boolean; error: string | null } {
    const { data, isLoading, error } = useJson<ThreadEnvironment>(`/threads/${thread.id}/environment`);

    return { environment: data, isLoading, error };
}
