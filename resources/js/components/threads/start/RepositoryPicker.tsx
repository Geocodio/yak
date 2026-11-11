import { Menu, cn } from '@geocodio/console-ui';
import { ChevronDown, Loader2, Plus } from 'lucide-react';
import { useState } from 'react';
import { repositoryAccess } from '@/routes/threads';
import type { StartRepository, StartRepositoryRole } from '@/types/threads';

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
}: {
    primary: string;
    extras: StartRepository[];
    available: string[];
    isResearch: boolean;
    disabled: boolean;
    onChange: (extras: StartRepository[]) => void;
}) {
    const [checking, setChecking] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const candidates = available.filter((slug) => slug !== primary && !extras.some((extra) => extra.repo === slug));

    const add = async (repo: string) => {
        setError(null);
        setChecking(repo);
        try {
            const response = await fetch(repositoryAccess.url({ query: { repo } }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const result: { allowed: boolean; reason: string | null } = await response.json();
            if (result.allowed) {
                onChange([...extras, { repo, role: 'changes' }]);
            } else {
                setError(result.reason ?? `Yak cannot reach ${repo}.`);
            }
        } catch {
            setError(`Could not check access to ${repo}. Try again.`);
        } finally {
            setChecking(null);
        }
    };

    const setRole = (repo: string, role: StartRepositoryRole) => onChange(extras.map((extra) => (extra.repo === repo ? { ...extra, role } : extra)));

    return (
        <div data-testid="repo-picker" className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <span className="inline-flex h-7 items-center gap-1.5 rounded-control border border-hair px-2.5 text-[12.5px] font-medium text-body" data-testid="repo-chip">
                    {primary}
                    <span className="text-[11px] text-faint">Primary</span>
                </span>
                {extras.map((extra) => {
                    const role = isResearch ? 'context' : extra.role;
                    return (
                        <span
                            key={extra.repo}
                            className={cn('inline-flex h-7 items-center gap-1.5 rounded-control border px-2.5 text-[12.5px] font-medium text-body', role === 'context' ? 'border-dashed border-hair' : 'border-hair')}
                            data-testid="repo-chip"
                        >
                            {extra.repo}
                            {isResearch ? (
                                <span className="text-[11px] text-faint">Context</span>
                            ) : (
                                <Menu
                                    aria-label={`Role of ${extra.repo}`}
                                    disabled={disabled}
                                    trigger={
                                        <span className="flex items-center gap-0.5 text-[11px] text-faint" data-testid="repo-role">
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
                    disabled={disabled || checking !== null || candidates.length === 0}
                    trigger={
                        <span className="inline-flex h-7 items-center gap-1 text-[12.5px] text-muted" data-testid="add-repo">
                            {checking ? <Loader2 size={12} className="animate-spin" aria-hidden="true" /> : <Plus size={12} aria-hidden="true" />}
                            {checking ? `Checking access to ${checking}` : 'Add repo'}
                        </span>
                    }
                    items={candidates.map((slug) => ({ key: slug, label: slug, onSelect: () => void add(slug) }))}
                />
            </div>
            {extras.length > 0 && !isResearch && (
                <p className="text-[12px] text-muted" data-testid="repo-role-help">
                    Changes: Yak edits it and opens a pull request. Context: Yak reads it, and anything it writes there is thrown away.
                </p>
            )}
            {error && (
                <p className="text-[12.5px] text-fail" role="alert" data-testid="repo-error">
                    {error}
                </p>
            )}
        </div>
    );
}
