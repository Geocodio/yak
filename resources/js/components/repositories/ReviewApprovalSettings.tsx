import { Button, Field, Select, Textarea, TextInput } from '@geocodio/console-ui';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ExpandableCodeField } from '@/components/editor/ExpandableCodeField';
import { ToggleRow } from '@/components/repositories/ToggleRow';
import { show as showPrompt } from '@/routes/prompts';
import type { RepositoryDetail, ReviewPolicy } from '@/types/repositories';

export function ReviewApprovalSettings({ value, onChange, errors, repository }: {
    value: ReviewPolicy;
    onChange: (policy: ReviewPolicy) => void;
    errors: Record<string, string | undefined>;
    repository: RepositoryDetail | null;
}) {
    const set = <K extends keyof ReviewPolicy>(key: K, next: ReviewPolicy[K]) => onChange({ ...value, [key]: next });
    return (
        <div className="space-y-4 rounded-card border border-hair bg-panel p-4" data-testid="review-approval-settings">
            <div>
                <h3 className="text-[13px] font-semibold">Risk-based approval</h3>
                <p className="mt-1 text-[12px] text-muted">Yak can approve eligible PRs by other authors. Yak-authored PRs need a human approval. Yak never merges.</p>
            </div>
            <Field label="Approval mode" error={errors['pr_review_policy.mode']}>
                <Select value={value.mode} onChange={(mode) => set('mode', (mode ?? 'off') as ReviewPolicy['mode'])} options={[
                    { value: 'off', label: 'Off: comments only' },
                    { value: 'shadow', label: 'Shadow: evaluate without approving' },
                    { value: 'enforce', label: 'Enforce: submit eligible approvals' },
                ]} />
            </Field>
            <p className="text-[12px] text-muted">Start in shadow mode. Save repository to apply these settings. Missing profiles, unknown paths or missing trusted CI always block approval.</p>
            {(['allowed_paths', 'blocked_paths'] as const).map((key) => (
                <Field key={key} label={key === 'allowed_paths' ? 'Allowed paths' : 'Additional blocked paths'} description="One glob per line, for example docs/**. Built-in security exclusions always apply." error={errors[`pr_review_policy.${key}`]}>
                    <Textarea value={value[key].join('\n')} onChange={(e) => set(key, e.target.value.split('\n'))} />
                </Field>
            ))}
            <Field label="Required GitHub checks" description="Use the exact check name and trusted GitHub App ID.">
                <div className="space-y-2">
                    {value.required_checks.map((check, index) => (
                        <div key={index} className="flex flex-wrap gap-2">
                            <TextInput aria-label={`Check ${index + 1} name`} value={check.name} onChange={(e) => set('required_checks', value.required_checks.map((row, i) => i === index ? { ...row, name: e.target.value } : row))} />
                            <TextInput aria-label={`Check ${index + 1} app ID`} type="number" min={1} value={check.app_id || ''} onChange={(e) => set('required_checks', value.required_checks.map((row, i) => i === index ? { ...row, app_id: Number(e.target.value) } : row))} />
                            <Button type="button" variant="secondary" onClick={() => set('required_checks', value.required_checks.filter((_, i) => i !== index))}>Remove</Button>
                        </div>
                    ))}
                    <Button type="button" variant="secondary" onClick={() => set('required_checks', [...value.required_checks, { name: '', app_id: 0 }])}>Add required check</Button>
                </div>
            </Field>
            <Field label="Required commit statuses" description="For Drone and similar providers: exact context and trusted creator user ID.">
                <div className="space-y-2">
                    {value.required_statuses.map((status, index) => (
                        <div key={index} className="flex flex-wrap gap-2">
                            <TextInput aria-label={`Status ${index + 1} context`} value={status.name} onChange={(e) => set('required_statuses', value.required_statuses.map((row, i) => i === index ? { ...row, name: e.target.value } : row))} />
                            <TextInput aria-label={`Status ${index + 1} creator ID`} type="number" min={1} value={status.creator_id || ''} onChange={(e) => set('required_statuses', value.required_statuses.map((row, i) => i === index ? { ...row, creator_id: Number(e.target.value) } : row))} />
                            <Button type="button" variant="secondary" onClick={() => set('required_statuses', value.required_statuses.filter((_, i) => i !== index))}>Remove</Button>
                        </div>
                    ))}
                    <Button type="button" variant="secondary" onClick={() => set('required_statuses', [...value.required_statuses, { name: '', creator_id: 0 }])}>Add required status</Button>
                </div>
            </Field>
            {([
                ['max_files', 'Maximum changed files', 1, 100],
                ['max_lines', 'Maximum changed lines', 1, 5000],
                ['max_risk_score', 'Maximum risk score', 0, 30],
                ['min_confidence', 'Minimum model confidence', 80, 100],
                ['profile_max_age_days', 'Profile validity (days)', 1, 90],
            ] as const).map(([key, label, min, max]) => (
                <Field key={key} label={label} error={errors[`pr_review_policy.${key}`]}>
                    <TextInput type="number" min={min} max={max} value={value[key]} onChange={(e) => set(key, Number(e.target.value))} />
                </Field>
            ))}
            {Object.entries(errors).filter(([key, error]) => key.startsWith('pr_review_policy.') && error).map(([key, error]) => <p key={key} role="alert" className="text-[12px] text-fail">{error}</p>)}
            <a className="text-[12px] text-accent-text hover:underline" href={showPrompt.url('tasks-risk-profile')}>Edit risk profile prompt</a>
            {' · '}
            <a className="text-[12px] text-accent-text hover:underline" href={showPrompt.url('tasks-review')}>Edit PR review prompt</a>
            {repository ? <RiskProfiles key={JSON.stringify(repository.riskProfiles)} repository={repository} /> : <p className="text-[12px] text-muted">Save the repository before generating a risk profile.</p>}
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
