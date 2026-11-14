import type { ModelFamily } from '@/types/claudeAccounts';

/** Mirrors the array shape in `App\Http\Resources\StackData`. */

export type StackChildState = 'queued' | 'working' | 'waiting' | 'done' | 'failed' | 'closed';

export type StackChild = {
    id: string;
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
    filesChanged: number | null;
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
    rootId: string;
    isRoot: boolean;
    parent: { id: string; name: string; title: string } | null;
    children: StackChild[];
    limit: { current: number; max: number; awake: number; waiting: number };
    maxChildren: number;
    models: ModelFamily[];
    parentAccountName: string | null;
    usage: { turnCount: number; costUsd: number; activeHours: number; note: string };
    messages: StackMessageRow[];
    canDrive: boolean;
    canStop: boolean;
};
