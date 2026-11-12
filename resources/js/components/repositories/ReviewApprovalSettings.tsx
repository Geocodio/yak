import { Button, Field, Select, TextInput } from '@geocodio/console-ui';
import { Link, useForm } from '@inertiajs/react';
import { Map, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { ExpandableCodeField } from '@/components/editor/ExpandableCodeField';
import { ToggleRow } from '@/components/repositories/ToggleRow';
import { show as showPrompt } from '@/routes/prompts';
import { show as showRiskProfile } from '@/routes/repos/risk-profile';
import { ConfigSourceBadge, ProposeChangeLink } from '@/components/repositories/ConfigSourceBadge';
import type { RepositoryDetail, ReviewPolicy, YakConfig } from '@/types/repositories';

export function ReviewApprovalSettings({ value, onChange, errors, repository, yakConfig }: {
    value: ReviewPolicy;
    onChange: (policy: ReviewPolicy) => void;
    errors: Record<string, string | undefined>;
    repository: RepositoryDetail | null;
    yakConfig?: YakConfig;
}) {
    const lockedRisk = yakConfig?.values.riskProfile;
    const set = <K extends keyof ReviewPolicy>(key: K, next: ReviewPolicy[K]) => onChange({ ...value, [key]: next });
    return (
        <div className="space-y-4 rounded-card border border-hair bg-panel p-4" data-testid="review-approval-settings">
            <div>
                <h3 className="flex flex-wrap items-center gap-2 text-[13px] font-semibold">
                    Risk-based approval
                </h3>
                <p className="mt-1 text-[12px] text-muted">Yak can approve eligible PRs by other authors. Yak-authored PRs need a human approval. Yak never merges.</p>
            </div>
                        <Field label="Profile validity (days)" error={errors['pr_review_policy.profile_max_age_days']}>
                <TextInput type="number" min={1} max={90} value={value.profile_max_age_days} onChange={(e) => set('profile_max_age_days', Number(e.target.value))} />
            </Field>
            {Object.entries(errors).filter(([key, error]) => key.startsWith('pr_review_policy.') && error).map(([key, error]) => <p key={key} role="alert" className="text-[12px] text-fail">{error}</p>)}
            <Link className="text-[12px] text-accent-text hover:underline" href={showPrompt.url('tasks-risk-profile')}>Edit risk profile prompt</Link>
            {' · '}
            <Link className="text-[12px] text-accent-text hover:underline" href={showPrompt.url('tasks-review')}>Edit PR review prompt</Link>
            {yakConfig && repository && lockedRisk ? <LockedRiskProfile profile={lockedRisk} config={yakConfig} branch={repository.defaultBranch} repositorySlug={repository.slug} /> : repository ? <RiskProfiles key={JSON.stringify(repository.riskProfiles)} repository={repository} /> : <p className="text-[12px] text-muted">Save the repository before generating a risk profile.</p>}
        </div>
    );
}

function OpenRiskProfileLink({ repository }: { repository: string }) {
    return (
        <Link className="inline-flex items-center gap-1.5 text-[12px] text-accent-text hover:underline" href={showRiskProfile(repository)}>
            <Map size={12} className="shrink-0" />Open risk profile
        </Link>
    );
}

function LockedRiskProfile({ profile, config, branch, repositorySlug }: { profile: NonNullable<YakConfig['values']['riskProfile']>; config: YakConfig; branch: string; repositorySlug: string }) {
    return (
        <div className="space-y-2 border-t border-hair pt-4" data-testid="locked-risk-profile">
            <h4 className="flex flex-wrap items-center gap-2 text-[13px] font-semibold">
                Repository risk profile
                <ConfigSourceBadge field="riskProfile" config={config} fileName="risk-profile.yml" />
            </h4>
            <p className="text-[13px] font-medium">{profile.areas} areas, {profile.unknowns} open {profile.unknowns === 1 ? 'question' : 'questions'}</p>
            <p className="text-[12px] text-muted">
                Approved by merging into {branch}.{profile.commitSha ? <> In use since <span className="font-mono">{profile.commitSha.slice(0, 7)}</span>.</> : null}
            </p>
            {profile.unknowns > 0 && <p className="flex items-center gap-1.5 text-[12px] text-muted"><TriangleAlert size={12} className="shrink-0 text-warn" />Open questions block Yak's auto-approval until they are answered in the file.</p>}
            <div className="flex flex-wrap items-center gap-4">
                <OpenRiskProfileLink repository={repositorySlug} />
                <ProposeChangeLink config={config} fileName="risk-profile.yml" />
            </div>
        </div>
    );
}

function RiskProfiles({ repository }: { repository: RepositoryDetail }) {
    const { active, drafts } = repository.riskProfiles;
    const [version, setVersion] = useState(drafts[0]?.version ?? '');
    const draft = drafts.find((item) => item.version === version);
    const [content, setContent] = useState(draft ? JSON.stringify(draft, null, 2) : '');
    const form = useForm({ action: 'generate', version, reviewed: false, content });
    const submit = (action: 'generate' | 'approve' | 'import') => {
        form.setData('action', action);
        form.transform(() => ({ action, ...(action === 'approve' ? { version, reviewed: form.data.reviewed } : {}), ...(action === 'import' ? { content } : {}) }));
        form.post(repository.riskProfileActionUrl, { preserveScroll: true });
    };
    return (
        <div className="space-y-3 border-t border-hair pt-4">
            <h4 className="text-[13px] font-semibold">Repository risk profile</h4>
            <OpenRiskProfileLink repository={repository.slug} />
            <p className="break-all text-[12px] text-muted">{active ? `Active: ${active.version}. Approved by ${active.approved_by} at ${active.approved_at}.` : 'No active profile. Generate and review a draft before enabling approvals.'}</p>
            {active && <details className="text-[12px] text-muted"><summary className="cursor-pointer">View active profile</summary><pre className="max-h-80 overflow-auto whitespace-pre-wrap break-words">{JSON.stringify(active, null, 2)}</pre></details>}
            <Button type="button" pending={form.processing && form.data.action === 'generate'} disabled={form.processing} onClick={() => { form.setData('action', 'generate'); submit('generate'); }}>Generate draft with Claude</Button>
            {draft && <>
                <Field label="Draft to review">
                    <Select value={version} options={drafts.map((item) => ({ value: item.version, label: `${item.version.slice(0, 12)} (source ${item.source_sha.slice(0, 12)})` }))} onChange={(next) => {
                        const selected = drafts.find((item) => item.version === next);
                        setVersion(next ?? ''); setContent(selected ? JSON.stringify(selected, null, 2) : ''); form.setData('reviewed', false);
                    }} />
                </Field>
                <p className="break-all text-[12px] text-muted">Source revision: {draft.source_sha}</p>
                {draft.areas.map((area, index) => <div key={index} className="space-y-1 rounded-control border border-hair p-3 text-[12px]">
                    <p className="font-medium">{area.name}: {area.risk}</p>
                    <p>{area.rationale}</p><p className="break-all text-muted">Paths: {area.paths.join(', ')}</p>
                    <p className="break-all text-muted">Symbols: {area.symbols.join(', ') || 'None specified'}</p>
                    <p className="break-all text-muted">Evidence: {area.evidence.join(', ')}</p>
                </div>)}
                {draft.unknowns.length > 0 && <p className="text-[12px] text-warn">Unresolved context: {draft.unknowns.join('; ')}</p>}
                <details className="text-[12px]"><summary className="cursor-pointer">Edit draft JSON</summary>
                    <ExpandableCodeField value={content} onChange={(next) => { setContent(next); form.setData('reviewed', false); }} title="Risk profile draft" ariaLabel="Risk profile draft JSON" />
                    <Button type="button" pending={form.processing && form.data.action === 'import'} disabled={form.processing} onClick={() => submit('import')}>Save as new draft</Button>
                    <p className="text-muted">Changes create a new version. Select and review that version before approving.</p>
                </details>
                <ToggleRow label="I reviewed this exact draft" description="Confirm the paths, risk levels, evidence and unknowns above. Approval records your signed-in user identity." checked={form.data.reviewed} onChange={(checked) => form.setData('reviewed', checked)} />
                <Button type="button" pending={form.processing && form.data.action === 'approve'} disabled={form.processing || !form.data.reviewed || content !== JSON.stringify(draft, null, 2)} onClick={() => submit('approve')}>Approve this profile</Button>
            </>}
            {Object.values(form.errors).map((error, index) => <p key={index} role="alert" className="text-[12px] text-fail">{error}</p>)}
        </div>
    );
}
