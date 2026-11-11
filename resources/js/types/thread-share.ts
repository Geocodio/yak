export type ThreadShareMode = 'only_me' | 'people' | 'everyone';

/** Mirrors `ThreadShareController::show`. */
export type ThreadShareState = {
    mode: ThreadShareMode;
    userIds: number[];
    people: { id: number; name: string; role: string | null }[];
    secretFiles: string[];
    memoryFolder: string | null;
    memoryFiles: string[];
};
