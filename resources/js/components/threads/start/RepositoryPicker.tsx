import { Menu, cn } from '@geocodio/console-ui';
import { ChevronDown, Loader2, Plus, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { RepositoryAvatar } from '@/components/threads/RepositoryAvatar';
import { repositoryAccess } from '@/routes/threads';
import type { StartRepository, StartRepositoryRole } from '@/types/threads';
import { CHIP_TAG, CHIP_TAG_ACCENT, CHIP_TRIGGER } from './chipStyles';

export const MAX_REPOSITORIES = 6;

/** An extra repository on the start form; `blocked` holds the reason Yak cannot reach it and is never sent. */
export type PickerRepository = StartRepository & { blocked?: string };

const CHIP = 'inline-flex h-7 items-center gap-1.5 rounded-lg border border-hair bg-panel pl-1 pr-1.5 text-[12.5px] font-medium text-body';
/** The slug as owner and name, the owner muted and the name in medium weight. */
function RepositoryName({ slug }: { slug: string }) {
    const separator = slug.lastIndexOf('/');

    return separator === -1 ? (
        <span>{slug}</span>
    ) : (
        <span>
            <span className="font-normal text-muted">{slug.slice(0, separator + 1)}</span>
            {slug.slice(separator + 1)}
        </span>
    );
}

const ROLE_LABELS: Record<StartRepositoryRole, string> = { changes: 'Changes', context: 'Context' };

/**
 * The repositories of a new thread: the primary chip (no role menu), one chip
 * per extra repository with a Changes or Context menu, and "Add repo", which
 * checks that Yak can reach the repository before the chip appears.
 */
export function RepositoryPicker({
    primary,
    extras,
    available,
    isResearch,
    disabled,
    onChange,
    onPrimaryChange,
    onBlockedChange,
    docsUrl,
}: {
    onBlockedChange: (blocked: boolean) => void;
    docsUrl: string;
    primary: string;
    onPrimaryChange: (slug: string) => void;
    extras: PickerRepository[];
    available: string[];
    isResearch: boolean;
    disabled: boolean;
    onChange: (extras: PickerRepository[]) => void;
}) {
    const [checking, setChecking] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const candidates = available.filter((slug) => slug !== primary && !extras.some((extra) => extra.repo === slug));

    const blockedRepo = extras.find((extra) => extra.blocked !== undefined);
    const isAtLimit = extras.length + 1 >= MAX_REPOSITORIES;

    useEffect(() => {
        onBlockedChange(blockedRepo !== undefined);
    }, [blockedRepo, onBlockedChange]);

    const add = async (repo: string) => {
        setError(null);
        setChecking(repo);
        try {
            const response = await fetch(repositoryAccess.url({ query: { repo } }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const result: { allowed: boolean; reason: string | null } = await response.json();
            onChange([...extras, result.allowed ? { repo, role: 'changes' } : { repo, role: 'changes', blocked: result.reason ?? 'Yak has no access to this repository.' }]);
        } catch {
            setError(`Could not check access to ${repo}. Try again.`);
        } finally {
            setChecking(null);
        }
    };

    const setRole = (repo: string, role: StartRepositoryRole) => onChange(extras.map((extra) => (extra.repo === repo ? { ...extra, role } : extra)));

    return (
        <div data-testid="repo-picker" className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
                <span className="mr-0.5 text-[12.5px] text-muted">Repositories</span>
                <span className="inline-flex" data-testid="repo-chip">
                    <Menu
                        aria-label="Primary repository"
                        disabled={disabled}
                        className={CHIP_TRIGGER}
                        trigger={
                            <span className="flex items-center gap-1.5" data-testid="start-thread-repo">
                                {primary !== '' && <RepositoryAvatar repo={primary} variant="owner" />}
                                {primary ? <RepositoryName slug={primary} /> : <span className="pl-1.5">Pick a repo</span>}
                                {primary !== '' && <span className={CHIP_TAG_ACCENT}>Primary</span>}
                                <ChevronDown size={13} className="text-faint" aria-hidden="true" />
                            </span>
                        }
                        items={available.map((slug) => ({ key: slug, label: slug, checked: slug === primary, onSelect: () => onPrimaryChange(slug) }))}
                    />
                </span>
                {extras.map((extra) => {
                    const role = isResearch ? 'context' : extra.role;
                    return (
                        <span
                            key={extra.repo}
                            className={cn(CHIP, role === 'context' && 'border-dashed', extra.blocked !== undefined && 'border-fail')}
                            data-testid="repo-chip"
                            data-unreachable={extra.blocked !== undefined ? 'true' : undefined}
                        >
                            {extra.blocked !== undefined && <TriangleAlert size={12} className="text-fail" aria-hidden="true" />}
                            <RepositoryAvatar repo={extra.repo} variant="owner" />
                            <RepositoryName slug={extra.repo} />
                            {isResearch ? (
                                <span className={CHIP_TAG}>Context</span>
                            ) : (
                                <Menu
                                    aria-label={`Role of ${extra.repo}`}
                                    disabled={disabled}
                                    trigger={
                                        <span className={cn(CHIP_TAG, 'inline-flex items-center gap-0.5')} data-testid="repo-role">
                                            {ROLE_LABELS[role]} <ChevronDown size={11} aria-hidden="true" />
                                        </span>
                                    }
                                    items={(Object.keys(ROLE_LABELS) as StartRepositoryRole[]).map((value) => ({
                                        key: value,
                                        label: ROLE_LABELS[value],
                                        checked: role === value,
                                        onSelect: () => setRole(extra.repo, value),
                                    }))}
                                />
                            )}
                        </span>
                    );
                })}
                <Menu
                    aria-label="Add repo"
                    disabled={disabled || checking !== null || candidates.length === 0 || isAtLimit}
                    trigger={
                        <span
                            className="inline-flex h-7 items-center gap-1.5 rounded-lg border border-dashed border-hair-strong pl-2 pr-2.5 text-[12.5px] font-medium text-muted transition-[color,border-color,background-color,transform] duration-150 hover:border-[var(--accent-edge)] hover:bg-[var(--accent-tint)] hover:text-accent-text active:scale-[0.97] motion-reduce:transition-none"
                            data-testid="add-repo"
                        >
                            {checking ? <Loader2 size={13} className="animate-spin" aria-hidden="true" /> : <Plus size={13} aria-hidden="true" />}
                            {checking ? `Checking access to ${checking}` : 'Add repo'}
                        </span>
                    }
                    items={candidates.map((slug) => ({ key: slug, label: slug, onSelect: () => void add(slug) }))}
                />
                {isAtLimit && (
                    <span className="text-[12px] text-muted" data-testid="repo-limit">
                        {MAX_REPOSITORIES} of {MAX_REPOSITORIES} repos. A thread can use up to {MAX_REPOSITORIES} repos.
                    </span>
                )}
            </div>
            {extras.length > 0 && !isResearch && (
                <p className="text-[12px] text-muted" data-testid="repo-role-help">
                    Changes: Yak edits it and opens a pull request. Context: Yak reads it, and anything it writes there is thrown away.
                </p>
            )}
            {blockedRepo && (
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-control bg-fail-soft px-3 py-2 text-[12.5px] text-body" role="alert" data-testid="repo-error">
                    <TriangleAlert size={13} className="shrink-0 text-fail" aria-hidden="true" />
                    <span>
                        <strong>Yak cannot reach {blockedRepo.repo}.</strong> {blockedRepo.blocked}
                    </span>
                    <button type="button" className="underline" onClick={() => onChange(extras.filter((extra) => extra.repo !== blockedRepo.repo))} data-testid="repo-remove-blocked">
                        Remove it
                    </button>
                    <a href={docsUrl} target="_blank" rel="noreferrer" className="underline">
                        How Yak gets access
                    </a>
                </div>
            )}
            {error && (
                <p className="text-[12.5px] text-fail" role="alert" data-testid="repo-error">
                    {error}
                </p>
            )}
        </div>
    );
}
