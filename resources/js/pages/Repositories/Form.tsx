import { Head, useForm } from '@inertiajs/react';
import { Badge, Button, Field, Select, StatusPill, Textarea, TextInput } from '@geocodio/console-ui';
import { ExternalLink, RefreshCw, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRouterAction } from '@/lib/useRouterAction';
import { AppLayout } from '@/layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { DangerZone } from '@/components/repositories/DangerZone';
import { ExpandableCodeField } from '@/components/editor/ExpandableCodeField';
import { shellLanguage } from '@/components/editor/shellLanguage';
import { GitHubRepoPicker } from '@/components/repositories/GitHubRepoPicker';
import { PathExcludes } from '@/components/repositories/PathExcludes';
import { SetupHistory } from '@/components/repositories/SetupHistory';
import { ToggleRow } from '@/components/repositories/ToggleRow';
import repos from '@/routes/repos';
import { tasks } from '@/routes';
import type { PageProps } from '@/types/shared';
import type {
    GitHubSearchRepo,
    ManifestData,
    RepositoryDetail,
    RepositoryOptions,
    RepositoryStats,
    SandboxData,
    SetupHistoryRow,
} from '@/types/repositories';

type Props = PageProps<{
    repository: RepositoryDetail | null;
    options: RepositoryOptions;
    manifest: ManifestData | null;
    sandbox: SandboxData | null;
    setupHistory: SetupHistoryRow[];
    stats: RepositoryStats | null;
    canDelete: boolean;
    deleteBlockedReason: string | null;
    docsUrl: string;
}>;

type FormData = {
    name: string;
    description: string;
    agent_instructions: string;
    git_url: string;
    default_branch: string;
    public_site_url: string;
    is_active: boolean;
    is_default: boolean;
    ci_system: string;
    sentry_project: string;
    pr_review_enabled: boolean;
    apply_to_open_prs: boolean;
    deployments_enabled: boolean;
    path_excludes: string[] | null;
    slug: string;
    path: string;
    selected_github_repo: string;
    selected_github_repo_id: number | null;
    manifest: ManifestData | null;
};

function Section({ id, title, description, children, aside }: { id: string; title: string; description?: string; children: ReactNode; aside?: ReactNode }) {
    return (
        <section id={id} className="grid grid-cols-[220px_1fr] gap-8 border-b border-hair py-8 first:pt-2 last:border-0">
            <div>
                <h2 className="text-[13px] font-semibold">{title}</h2>
                {description && <p className="mt-1 text-[12px] leading-relaxed text-muted">{description}</p>}
                {aside}
            </div>
            <div className="flex flex-col gap-6">{children}</div>
        </section>
    );
}

const shellHighlighting = [shellLanguage()];

/**
 * Preview manifest fields, bound directly to the page's single form. Saved
 * together with the repository fields by the header's "Save repository"
 * button -- there is no separate submit here.
 */
function ManifestSection({
    repository,
    manifest,
    errors,
    onChange,
}: {
    repository: RepositoryDetail;
    manifest: ManifestData;
    errors: Partial<Record<'manifest.port' | 'manifest.health_probe_path' | 'manifest.wake_timeout_seconds', string>>;
    onChange: (manifest: ManifestData) => void;
}) {
    const action = useRouterAction();

    const setField = <K extends keyof ManifestData>(key: K, value: ManifestData[K]) => {
        onChange({ ...manifest, [key]: value });
    };

    return (
        <Section id="branch-deployments" title="Branch deployments" description="How the preview for this repository is built and served.">
            <div className="grid grid-cols-2 gap-4">
                <Field label="Port" description="Container port serving HTTP inside the preview." error={errors['manifest.port']}>
                    <TextInput type="number" value={manifest.port} onChange={(e) => setField('port', Number(e.target.value))} />
                </Field>
                <Field
                    label="Health probe path"
                    description="Path that returns a 2xx response once the app is ready to serve traffic."
                    error={errors['manifest.health_probe_path']}
                >
                    <TextInput value={manifest.healthProbePath} onChange={(e) => setField('healthProbePath', e.target.value)} />
                </Field>
            </div>
            <Field label="Cold start command" description="Brings services up from a stopped container, e.g. docker compose up -d.">
                <ExpandableCodeField
                    value={manifest.coldStart}
                    onChange={(value) => setField('coldStart', value)}
                    languageExtensions={shellHighlighting}
                    title="Cold start command"
                    ariaLabel="Cold start command"
                    data-testid="manifest-cold-start"
                />
            </Field>
            <Field
                label="Checkout refresh command"
                description="Full rebuild run on every push to the branch (image builds, dependency installs, migrations, cache clears). If the repo has a .yak/preview.sh script, Yak runs that instead."
            >
                <ExpandableCodeField
                    value={manifest.checkoutRefresh}
                    onChange={(value) => setField('checkoutRefresh', value)}
                    languageExtensions={shellHighlighting}
                    title="Checkout refresh command"
                    ariaLabel="Checkout refresh command"
                    data-testid="manifest-checkout-refresh"
                />
            </Field>
            <Field
                label="Wake timeout (seconds)"
                description="Overall cap on wake-plus-refresh time before a request to a hibernated preview gives up."
                error={errors['manifest.wake_timeout_seconds']}
            >
                <TextInput type="number" value={manifest.wakeTimeoutSeconds} onChange={(e) => setField('wakeTimeoutSeconds', Number(e.target.value))} />
            </Field>
            <div>
                <Button
                    icon={<RefreshCw size={13} />}
                    pending={action.isPending('rebuild')}
                    pendingLabel="Rebuilding…"
                    onClick={() => action.run('rebuild', 'post', repos.rebuildDeployments.url(repository.slug))}
                >
                    Rebuild all deployments
                </Button>
            </div>
        </Section>
    );
}

export default function Form({ repository, options, manifest, sandbox, setupHistory, stats, canDelete, deleteBlockedReason, docsUrl }: Props) {
    const isEditing = repository !== null;
    const showManifest = isEditing && repository.deploymentsEnabled && manifest !== null;

    const action = useRouterAction();

    const form = useForm<FormData>({
        name: repository?.name ?? '',
        description: repository?.description ?? '',
        agent_instructions: repository?.agentInstructions ?? '',
        git_url: repository?.gitUrl ?? '',
        default_branch: repository?.defaultBranch ?? 'main',
        public_site_url: repository?.publicSiteUrl ?? '',
        is_active: repository?.isActive ?? true,
        is_default: repository?.isDefault ?? false,
        ci_system: repository?.ciSystem ?? 'github_actions',
        sentry_project: repository?.sentryProject ?? '',
        pr_review_enabled: repository?.prReviewEnabled ?? false,
        apply_to_open_prs: true,
        deployments_enabled: repository?.deploymentsEnabled ?? false,
        path_excludes: repository?.pathExcludes ?? null,
        slug: repository?.slug ?? '',
        path: repository?.path ?? '',
        selected_github_repo: '',
        selected_github_repo_id: null,
        manifest,
    });

    const manifestErrors = form.errors as Partial<Record<'manifest.port' | 'manifest.health_probe_path' | 'manifest.wake_timeout_seconds', string>>;

    const submit = () => {
        if (isEditing && repository) {
            form.transform((data) => ({
                ...data,
                manifest: showManifest && data.manifest
                    ? {
                          port: data.manifest.port,
                          health_probe_path: data.manifest.healthProbePath,
                          cold_start: data.manifest.coldStart,
                          checkout_refresh: data.manifest.checkoutRefresh,
                          wake_timeout_seconds: data.manifest.wakeTimeoutSeconds,
                      }
                    : undefined,
            }));
            form.patch(repos.update.url(repository.slug), { preserveScroll: true });
        } else {
            form.post(repos.store.url());
        }
    };

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
        form.setData((data) => ({
            ...data,
            selected_github_repo: '',
            selected_github_repo_id: null,
            name: '',
            git_url: '',
            default_branch: 'main',
        }));
    };

    const onCiDetected = (ciSystem: string) => {
        form.setData('ci_system', ciSystem);
    };

    return (
        <>
            <Head title={isEditing ? `Edit ${repository.slug}` : 'Add repository'} />
            <PageHeader
                crumbs={['Repositories', isEditing ? repository.slug : 'Add new']}
                actions={
                    <>
                        {isEditing && repository.githubUrl && (
                            <a href={repository.githubUrl} target="_blank" rel="noopener noreferrer">
                                <Button icon={<ExternalLink size={13} />}>Open on GitHub</Button>
                            </a>
                        )}
                        {isEditing && repository && (
                            <Button
                                icon={<RefreshCw size={13} />}
                                pending={action.isPending('rerun-setup')}
                                pendingLabel="Dispatching…"
                                onClick={() => action.run('rerun-setup', 'post', repos.rerunSetup.url(repository.slug))}
                                title="Tears down the sandbox's dev environment and rebuilds it from scratch -- README, CLAUDE.md, dependencies, migrations, and a fresh sandbox snapshot."
                            >
                                Re-run setup
                            </Button>
                        )}
                        <Button variant="primary" pending={form.processing} onClick={submit} data-testid="save-repository">
                            {isEditing ? 'Save repository' : 'Add repository'}
                        </Button>
                    </>
                }
            />

            <div className="min-h-0 flex-1 overflow-auto">
                <div className="mx-auto max-w-[920px] px-8 py-6">
                    {isEditing && repository && (
                        <div className="mb-2 flex items-center gap-3">
                            <h1 className="text-[20px] font-semibold tracking-tight">{repository.slug}</h1>
                            <StatusPill tone={repository.isActive ? 'ok' : 'idle'} label={repository.isActive ? 'Active' : 'Inactive'} />
                            {sandbox && sandbox.baseVersion !== null && sandbox.baseVersion !== sandbox.latestBaseVersion && (
                                <Badge tone="warn">
                                    Base v{sandbox.baseVersion} &rarr; v{sandbox.latestBaseVersion}
                                </Badge>
                            )}
                            {stats && (
                                <span className="ml-auto flex items-center gap-4 text-[12px] text-faint">
                                    <span>
                                        <span className="tnum text-muted">{stats.tasks}</span> tasks
                                    </span>
                                    <span>
                                        <span className="tnum text-muted">{stats.tasks7d}</span> in 7d
                                    </span>
                                    <span>
                                        <span className="tnum text-muted">{stats.reviews30d}</span> reviews in 30d
                                    </span>
                                </span>
                            )}
                        </div>
                    )}

                    <p className="mb-6 max-w-[720px] text-[13px] leading-relaxed text-muted">
                        This page configures how Yak clones and automates this repository -- where it comes from, what agent instructions it gets, and
                        whether Yak reviews its pull requests.
                        {isEditing && repository.deploymentsEnabled && (
                            <>
                                {' '}
                                With branch deployments enabled, Yak also serves a preview deployment for every open PR branch; each preview hibernates
                                after a period of inactivity and wakes automatically on the next request.
                            </>
                        )}{' '}
                        See the{' '}
                        <a href={docsUrl} target="_blank" rel="noopener noreferrer" className="text-accent-text hover:underline">
                            repositories guide
                        </a>{' '}
                        for details.
                    </p>

                    {!isEditing && (
                        <Section id="github" title="GitHub repository" description="Pick the repository Yak should clone. It dispatches a setup task after saving.">
                            <GitHubRepoPicker
                                selected={form.data.selected_github_repo ? { fullName: form.data.selected_github_repo, defaultBranch: form.data.default_branch } : null}
                                sentryProjects={options.sentryProjects}
                                onSelect={onSelectGithubRepo}
                                onClear={clearGithubRepo}
                                onCiDetected={onCiDetected}
                            />
                        </Section>
                    )}

                    <Section id="basics" title="Basics" description="How Yak identifies this repository and where it clones from.">
                        <div className="grid grid-cols-2 gap-4">
                            {isEditing && repository && (
                                <Field label="Slug" description="Auto-generated. Cannot be changed.">
                                    <TextInput value={repository.slug} disabled readOnly />
                                </Field>
                            )}
                            {isEditing && repository && repository.githubNameDiverged && (
                                <Field
                                    label="GitHub repository"
                                    description="Renamed on GitHub. Yak keeps the original slug so tasks, previews and the sandbox template stay put."
                                >
                                    <TextInput value={repository.githubFullName} disabled readOnly />
                                </Field>
                            )}
                            <Field label="Display name" error={form.errors.name}>
                                <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            </Field>
                            <Field label="Git URL" description="HTTPS clone URL. Authenticated via the GitHub App." error={form.errors.git_url}>
                                <TextInput
                                    className="font-mono text-[12px]"
                                    value={form.data.git_url}
                                    onChange={(e) => form.setData('git_url', e.target.value)}
                                    disabled={!isEditing && form.data.selected_github_repo !== ''}
                                />
                            </Field>
                            <Field label="Default branch" error={form.errors.default_branch}>
                                <TextInput
                                    className="font-mono text-[12px]"
                                    value={form.data.default_branch}
                                    onChange={(e) => form.setData('default_branch', e.target.value)}
                                />
                            </Field>
                        </div>
                        <Field
                            label="Description"
                            description="One-line description of what this repo does. Used to route natural-language tasks to the right repo."
                            error={form.errors.description}
                        >
                            <Textarea rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </Field>
                        {isEditing && (
                            <Field
                                label="Agent instructions"
                                description="Freeform notes appended to every task's system prompt for this repo. Leave empty to use only the global rules."
                                error={form.errors.agent_instructions}
                            >
                                <ExpandableCodeField
                                    value={form.data.agent_instructions}
                                    onChange={(value) => form.setData('agent_instructions', value)}
                                    languageExtensions={[]}
                                    title="Agent instructions"
                                    ariaLabel="Agent instructions"
                                    data-testid="agent-instructions"
                                />
                            </Field>
                        )}
                        {isEditing && (
                            <div className="grid grid-cols-3 gap-4">
                                <Field label="Public site URL" description="Shown in the video's browser bar instead of the sandbox address." error={form.errors.public_site_url}>
                                    <TextInput
                                        placeholder="https://www.example.com"
                                        value={form.data.public_site_url}
                                        onChange={(e) => form.setData('public_site_url', e.target.value)}
                                    />
                                </Field>
                                <Field label="CI system" error={form.errors.ci_system}>
                                    <Select options={options.ciSystems} value={form.data.ci_system} onChange={(v) => form.setData('ci_system', v ?? 'none')} />
                                </Field>
                                {options.sentryProjects.length > 0 ? (
                                    <Field label="Sentry project">
                                        <Select
                                            options={[{ value: '', label: 'None' }, ...options.sentryProjects]}
                                            value={form.data.sentry_project}
                                            onChange={(v) => form.setData('sentry_project', v ?? '')}
                                        />
                                    </Field>
                                ) : (
                                    <Field label="Sentry project slug" description="Maps incoming Sentry webhooks to this repository.">
                                        <TextInput
                                            placeholder="my-project"
                                            value={form.data.sentry_project}
                                            onChange={(e) => form.setData('sentry_project', e.target.value)}
                                        />
                                    </Field>
                                )}
                            </div>
                        )}
                    </Section>

                    <Section id="automation" title="Automation" description="What Yak does on its own for this repository.">
                        {isEditing && (
                            <ToggleRow
                                label="Active"
                                description="Yak accepts tasks for this repository."
                                checked={form.data.is_active}
                                onChange={(v) => form.setData('is_active', v)}
                            />
                        )}
                        <ToggleRow
                            label="Default repository"
                            description="Tasks that name no repository land here. Only one repository can be the default."
                            checked={form.data.is_default}
                            onChange={(v) => form.setData('is_default', v)}
                        />
                        <ToggleRow
                            label="PR review"
                            description="Have Yak review every open, non-draft pull request on this repo."
                            checked={form.data.pr_review_enabled}
                            onChange={(v) => form.setData('pr_review_enabled', v)}
                        />
                        {form.data.pr_review_enabled && !(repository?.prReviewEnabled ?? false) && (
                            <ToggleRow
                                label="Review all currently open PRs on save"
                                description="Enqueues a review task for every open, non-draft pull request once this repository is saved."
                                checked={form.data.apply_to_open_prs}
                                onChange={(v) => form.setData('apply_to_open_prs', v)}
                            />
                        )}
                        {isEditing && repository && form.data.pr_review_enabled && repository.prReviewEnabled && (
                            <div>
                                <Button
                                    pending={action.isPending('review-open-prs')}
                                    pendingLabel="Queueing reviews…"
                                    onClick={() => action.run('review-open-prs', 'post', repos.reviewOpenPrs.url(repository.slug))}
                                >
                                    Re-review all open PRs
                                </Button>
                            </div>
                        )}
                        {form.data.pr_review_enabled && (
                            <PathExcludes value={form.data.path_excludes} defaults={options.defaultPathExcludes} onChange={(v) => form.setData('path_excludes', v)} />
                        )}
                        {isEditing && (
                            <ToggleRow
                                label="Branch deployments"
                                description="Serve a preview deployment for every open PR branch on this repo, each in its own isolated container that hibernates when idle."
                                checked={form.data.deployments_enabled}
                                onChange={(v) => form.setData('deployments_enabled', v)}
                            />
                        )}
                    </Section>

                    {!isEditing && (
                        <div className="mb-6 flex items-start gap-3 rounded-card border border-hair bg-panel-2 p-4">
                            <div className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent">
                                <Sparkles size={14} />
                            </div>
                            <p className="text-[13px] leading-relaxed text-muted">
                                <span className="font-medium text-accent">After saving,</span> Yak dispatches a setup task -- Claude Code reads
                                your README and CLAUDE.md, prepares the dev environment, and verifies everything works.
                            </p>
                        </div>
                    )}

                    {isEditing && repository && showManifest && form.data.manifest && (
                        <ManifestSection
                            repository={repository}
                            manifest={form.data.manifest}
                            errors={manifestErrors}
                            onChange={(next) => form.setData('manifest', next)}
                        />
                    )}

                    {isEditing && sandbox && (
                        <Section id="sandbox" title="Sandbox template" description="The snapshot every task for this repository starts from.">
                            <dl className="grid grid-cols-[140px_1fr] gap-y-2 text-[12px]">
                                <dt className="text-faint">Snapshot</dt>
                                <dd className="font-mono">{sandbox.snapshot ?? '—'}</dd>
                                <dt className="text-faint">yak-base version</dt>
                                <dd className="flex items-center gap-2">
                                    {sandbox.baseVersion === null ? (
                                        <span className="text-muted">— (not provisioned)</span>
                                    ) : (
                                        <>
                                            <span className="font-mono">v{sandbox.baseVersion}</span>
                                            {sandbox.baseVersion !== sandbox.latestBaseVersion && <Badge tone="warn">v{sandbox.latestBaseVersion} available</Badge>}
                                        </>
                                    )}
                                </dd>
                            </dl>
                        </Section>
                    )}

                    {isEditing && repository && (
                        <Section
                            id="setup-history"
                            title="Setup history"
                            description="Setup tasks prepare the dev environment and verify it works."
                        >
                            <SetupHistory rows={setupHistory} viewAllHref={setupHistory.length > 0 ? tasks.url({ query: { tab: 'setup', repo: repository.slug } }) : null} />
                        </Section>
                    )}

                    {isEditing && repository && (
                        <Section id="danger-zone" title="Danger zone">
                            <DangerZone
                                slug={repository.slug}
                                isActive={form.data.is_active}
                                canDelete={canDelete}
                                deleteBlockedReason={deleteBlockedReason}
                                processing={form.processing || action.isPending('delete')}
                                onToggleActive={() => action.run('toggle-active', 'post', repos.toggleActive.url(repository.slug))}
                                onDelete={() => action.run('delete', 'delete', repos.destroy.url(repository.slug))}
                                togglingActive={action.isPending('toggle-active')}
                            />
                        </Section>
                    )}
                </div>
            </div>
        </>
    );
}

Form.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
