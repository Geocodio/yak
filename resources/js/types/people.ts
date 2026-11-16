/** Mirrors App\Http\Resources\PersonData. */
export type Person = {
    id: number;
    name: string;
    email: string;
    initials: string;
    joinedAt: string;
    role: 'admin' | 'member';
    isYou: boolean;
    isLocked: boolean;
    lockReason: string | null;
};
