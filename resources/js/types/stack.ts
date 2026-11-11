/** Mirrors the array shape in `App\Http\Resources\StackData`. */

export type StackChildState = 'queued' | 'working' | 'waiting' | 'done' | 'failed' | 'closed';

export type StackChild = {
    id: number;
    name: string;
    title: string;
    kind: string;
    model: string | null;
    state: StackChildState;
    step: string | null;
    resultSummary: string | null;
    branch: string | null;
    repo: string | null;
    pullRequestUrl: string | null;
    ciStatus: string | null;
    queued: boolean;
    messagesPaused: boolean;
    takenOver: boolean;
    canOverride: boolean;
};

export type StackMessageRow = {
    id: number;
    fromName: string;
    toName: string;
    kind: string;
    text: string;
    createdAt: string;
};

export type StackData = {
    rootId: number;
    isRoot: boolean;
    parent: { id: number; name: string; title: string } | null;
    children: StackChild[];
    limit: { current: number; max: number };
    usage: { turnCount: number; costUsd: number; activeHours: number; note: string };
    messages: StackMessageRow[];
    canDrive: boolean;
    canStop: boolean;
};
