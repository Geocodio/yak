import { Head, useForm } from '@inertiajs/react';
import { Button, Checkbox, Field, PageHeader, Select, TextInput, Toggle } from '@geocodio/console-ui';
import { Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { GitHubRepoPicker } from '@/components/repositories/GitHubRepoPicker';
import { InfoTip } from '@/components/repositories/settings/InfoTip';
import { SettingRow, SettingsCard } from '@/components/repositories/settings/SettingsCard';
import { AppLayout } from '@/layouts/AppLayout';
import repos from '@/routes/repos';
import type { PageProps } from '@/types/shared';
import type { GitHubSearchRepo, RepositoryDocsLinks, RepositoryOptions } from '@/types/repositories';

type Props = PageProps<{ options: RepositoryOptions; docsLinks: RepositoryDocsLinks }>;

export default function Create({ options, docsLinks }: Props) {
    const form = useForm({
        name: '',
        description: '',
        git_url: '',
        default_branch: 'main',
        ci_system: 'github_actions',
        sentry_project: '',
        pr_review_enabled: false,
        apply_to_open_prs: true,
        selected_github_repo: '',
        selected_github_repo_id: null as number | null,
    });

    const onSelectGithubRepo = (repo: GitHubSearchRepo, guessedSentryProject: string | null) => {
        form.setData((data) => ({
            ...data,
            selected_github_repo: repo.fullName,
            selected_github_repo_id: repo.id,
            name: repo.name,
            description: repo.description ?? '',
            git_url: repo.cloneUrl,
            default_branch: repo.defaultBranch,
            sentry_project: data.sentry_project === '' ? (guessedSentryProject ?? '') : data.sentry_project,
        }));
    };

    const clearGithubRepo = () => {
        form.setData((data) => ({ ...data, selected_github_repo: '', selected_github_repo_id: null, name: '', git_url: '', default_branch: 'main' }));
    };

    return (
        <>
            <Head title="Add repository" />
            <PageHeader crumbs={['Repositories', 'Add new']} />
            <div className="min-h-0 flex-1 overflow-auto">
                <div className="mx-auto w-full max-w-[760px] px-6 py-6">
                    <h1 className="mb-1 text-[20px] font-semibold tracking-tight">Add repository</h1>
                    <p className="mb-6 text-[13px] leading-relaxed text-muted">
                        Pick the repository Yak should clone. See the{' '}
                        <a href={docsLinks.adding} target="_blank" rel="noopener noreferrer" className="text-accent-text hover:underline">adding guide</a> for details.
                    </p>

                    <SettingsCard title="GitHub repository" description="Pick the repository Yak should clone.">
                        <GitHubRepoPicker
                            selected={form.data.selected_github_repo ? { fullName: form.data.selected_github_repo, defaultBranch: form.data.default_branch } : null}
                            sentryProjects={options.sentryProjects}
                            onSelect={onSelectGithubRepo}
                            onClear={clearGithubRepo}
                            onCiDetected={(ciSystem) => form.setData('ci_system', ciSystem)}
                        />
                    </SettingsCard>

                    <SettingsCard title="Details">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Display name" error={form.errors.name}>
                                <div className="flex flex-wrap items-center gap-2">
                                    <TextInput className="flex-1" data-testid="repository-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                    <InfoTip label="Shown in the dashboard and in task lists. Defaults to the GitHub repo name." />
                                </div>
                            </Field>
                            <Field label="Default branch" error={form.errors.default_branch}>
                                <TextInput className="font-mono text-[12px]" value={form.data.default_branch} onChange={(e) => form.setData('default_branch', e.target.value)} />
                            </Field>
                            <Field label="Git URL" description="HTTPS clone URL. Authenticated via the GitHub App." error={form.errors.git_url}>
                                <TextInput
                                    className="font-mono text-[12px]"
                                    data-testid="repository-git-url"
                                    value={form.data.git_url}
                                    onChange={(e) => form.setData('git_url', e.target.value)}
                                    disabled={form.data.selected_github_repo !== ''}
                                />
                            </Field>
                            <Field label="CI system" error={form.errors.ci_system}>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Select className="flex-1" options={options.ciSystems} value={form.data.ci_system} onChange={(v) => form.setData('ci_system', v ?? 'none')} />
                                    <InfoTip label="Where Yak reads CI results from after it pushes a branch. Detected from the repo when you pick it." />
                                </div>
                            </Field>
                        </div>
                    </SettingsCard>

                    <SettingsCard title="PR review">
                        <SettingRow label="Review pull requests" description="Have Yak review every open, non-draft pull request on this repo.">
                            <Toggle checked={form.data.pr_review_enabled} onCheckedChange={(v) => form.setData('pr_review_enabled', v)} label="Review pull requests" />
                        </SettingRow>
                        {form.data.pr_review_enabled && (
                            <div className="pt-3">
                                <Checkbox label="Also review the open PRs once the repository is added" checked={form.data.apply_to_open_prs} onCheckedChange={(v) => form.setData('apply_to_open_prs', v)} />
                            </div>
                        )}
                    </SettingsCard>

                    <div className="mb-6 flex items-start gap-3 rounded-card border border-hair bg-panel-2 p-4">
                        <div className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent">
                            <Sparkles size={14} />
                        </div>
                        <p className="text-[13px] leading-relaxed text-muted">After saving, Yak runs a setup task.</p>
                    </div>

                    <div className="flex items-center justify-end gap-2 border-t border-hair pt-4">
                        <Button variant="primary" pending={form.processing} onClick={() => form.post(repos.store.url())} data-testid="save-repository">
                            Add repository
                        </Button>
                    </div>
                </div>
            </div>
        </>
    );
}

Create.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
