import { Deferred, useForm } from '@inertiajs/react';
import { Field, Textarea, TextInput } from '@geocodio/console-ui';
import { BookOpen } from 'lucide-react';
import type { ReactNode } from 'react';
import { ExpandableCodeField } from '@/components/editor/ExpandableCodeField';
import { ConfigSourceBadge, LockedField } from '@/components/repositories/ConfigSourceBadge';
import { InfoTip } from '@/components/repositories/settings/InfoTip';
import { InstantSelectRow, InstantToggle } from '@/components/repositories/settings/InstantToggle';
import { SectionSaveBar } from '@/components/repositories/settings/SectionSaveBar';
import { FieldSkeleton, FieldStack, SectionIntro, SettingRow, SettingsCard } from '@/components/repositories/settings/SettingsCard';
import { SetupChecklist } from '@/components/repositories/settings/SetupChecklist';
import { AppLayout } from '@/layouts/AppLayout';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import { useLeaveGuard } from '@/lib/useLeaveGuard';
import repos from '@/routes/repos';
import type { PageProps } from '@/types/shared';
import type { RepositoryDocsLinks, RepositoryOptions, SandboxData, YakConfig } from '@/types/repositories';
import type { GeneralRepository, RepositorySettingsHeader } from '@/types/repositorySettings';

type Props = PageProps<{
    header: RepositorySettingsHeader;
    repository: GeneralRepository;
    options: RepositoryOptions;
    sandbox: SandboxData;
    docsLinks: RepositoryDocsLinks;
    shadowDays: number;
    concernsPullRequest?: { number: number; url: string } | null;
    yakConfig?: YakConfig;
}>;

function DocsLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <a href={href} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[12px] text-accent-text hover:underline">
            <BookOpen size={11} /> {children}
        </a>
    );
}

export default function General({ header, repository, options, sandbox, docsLinks, shadowDays, concernsPullRequest, yakConfig }: Props) {
    const fileValues = yakConfig?.values;
    const isReadingConfig = yakConfig === undefined;
    const isSentryListed = options.sentryProjects.length > 0;

    const isDescriptionLocked = fileValues?.description != null;
    const isInstructionsLocked = fileValues?.agentInstructions != null;
    const isPublicSiteUrlLocked = fileValues?.publicSiteUrl != null;
    const isCiSystemLocked = fileValues?.ciSystem != null;
    const lockedCount = [isDescriptionLocked, isInstructionsLocked, isPublicSiteUrlLocked, isCiSystemLocked].filter(Boolean).length;

    const form = useForm({
        name: repository.name,
        default_branch: repository.defaultBranch,
        git_url: repository.gitUrl ?? '',
        public_site_url: repository.publicSiteUrl ?? '',
        sentry_project: repository.sentryProject ?? '',
        description: repository.description ?? '',
        agent_instructions: repository.agentInstructions ?? '',
    });
    const { dialog } = useLeaveGuard(form.isDirty);

    const save = () => {
        form.transform((data) => {
            const { public_site_url, description, agent_instructions, sentry_project, ...rest } = data;

            return {
                ...rest,
                ...(isPublicSiteUrlLocked ? {} : { public_site_url }),
                ...(isDescriptionLocked ? {} : { description }),
                ...(isInstructionsLocked ? {} : { agent_instructions }),
                ...(isSentryListed ? {} : { sentry_project }),
            };
        });
        form.patch(repos.update.url(repository.slug), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    const discard = () => {
        form.reset();
        form.clearErrors();
    };

    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="general" dirtySections={form.isDirty ? ['general'] : []} branch={repository.defaultBranch}>
            <SectionIntro
                title="General"
                description={
                    lockedCount > 0
                        ? `${lockedCount} of 4 settings here come from .yak files. Those change through a pull request.`
                        : 'How Yak identifies this repository, what it reads before a task, and what it does on its own.'
                }
            />

            <Deferred data="yakConfig" fallback={<span />}>
                {yakConfig ? <SetupChecklist header={header} yakConfig={yakConfig} hasSandbox={sandbox.snapshot !== null} shadowDays={shadowDays} concernsPullRequest={concernsPullRequest} /> : null}
            </Deferred>

            <SettingsCard title="Identity" description="Saved with the save bar." docsHref={docsLinks.adding}>
                <FieldStack>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Slug" description="Auto-generated. Cannot be changed.">
                            <TextInput value={repository.slug} disabled readOnly />
                        </Field>
                        {repository.githubNameDiverged && (
                            <Field label="GitHub repository" description="Renamed on GitHub. Yak keeps the original slug so tasks, previews and the sandbox template stay put.">
                                <TextInput value={repository.githubFullName} disabled readOnly />
                            </Field>
                        )}
                        <Field label="Display name" error={form.errors.name}>
                            <div className="flex flex-wrap items-center gap-2">
                                <TextInput className="flex-1" data-testid="repository-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                <InfoTip label="Shown in the dashboard and in task lists. Defaults to the GitHub repo name." />
                            </div>
                        </Field>
                        <Field label="Default branch" error={form.errors.default_branch}>
                            <div className="flex flex-wrap items-center gap-2">
                                <TextInput className="flex-1 font-mono text-[12px]" value={form.data.default_branch} onChange={(e) => form.setData('default_branch', e.target.value)} />
                                <InfoTip label="Branch Yak bases task branches on and opens pull requests against." />
                            </div>
                            <a href={docsLinks.refresh} target="_blank" rel="noopener noreferrer" className="mt-1 inline-flex items-center gap-1 text-[11px] text-accent-text hover:underline">
                                <BookOpen size={11} /> How refresh works
                            </a>
                        </Field>
                        <Field label="Git URL" description="HTTPS clone URL. Authenticated via the GitHub App." error={form.errors.git_url}>
                            <TextInput className="font-mono text-[12px]" value={form.data.git_url} onChange={(e) => form.setData('git_url', e.target.value)} />
                        </Field>
                        {isReadingConfig ? (
                            <FieldSkeleton label="Public site URL" height="h-[34px]" />
                        ) : isPublicSiteUrlLocked ? (
                            <LockedField label="Public site URL" field="publicSiteUrl" config={yakConfig} fileName="config.yml">
                                {fileValues.publicSiteUrl}
                            </LockedField>
                        ) : (
                            <Field label="Public site URL" description="Shown in the video's browser bar instead of the sandbox address." error={form.errors.public_site_url}>
                                <TextInput placeholder="https://www.example.com" value={form.data.public_site_url} onChange={(e) => form.setData('public_site_url', e.target.value)} />
                            </Field>
                        )}
                        {!isSentryListed && (
                            <Field label="Sentry project slug" description="Maps incoming Sentry webhooks to this repository.">
                                <TextInput placeholder="my-project" value={form.data.sentry_project} onChange={(e) => form.setData('sentry_project', e.target.value)} />
                            </Field>
                        )}
                    </div>
                </FieldStack>
            </SettingsCard>

            <SettingsCard title="Agent context" description="What Yak reads before it starts a task. Saved with the save bar.">
                <FieldStack>
                    {isReadingConfig ? (
                        <FieldSkeleton label="Description" height="h-[58px]" />
                    ) : isDescriptionLocked ? (
                        <LockedField label="Description" field="description" config={yakConfig} fileName="config.yml" propose>
                            {fileValues.description}
                        </LockedField>
                    ) : (
                        <Field label="Description" description="One-line description of what this repo does. Used to route natural-language tasks to the right repo." error={form.errors.description}>
                            <Textarea rows={2} data-testid="repository-description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </Field>
                    )}
                    <DocsLink href={docsLinks.routing}>See how routing works</DocsLink>
                    {isReadingConfig ? (
                        <FieldSkeleton label="Agent instructions" height="h-[120px]" />
                    ) : isInstructionsLocked ? (
                        <LockedField label="Agent instructions" field="agentInstructions" config={yakConfig} fileName="AGENTS.md" mono propose>
                            {fileValues.agentInstructions}
                        </LockedField>
                    ) : (
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
                    <DocsLink href={docsLinks.claudeMd}>See CLAUDE.md guidance</DocsLink>
                </FieldStack>
            </SettingsCard>

            <SettingsCard title="Automation" description="Switches and choices here save as soon as you change them." docsHref={docsLinks.prReview}>
                <InstantToggle
                    slug={repository.slug}
                    field="is_default"
                    label="Default repository"
                    description="Tasks that name no repository land here. Only one repository can be the default."
                    checked={repository.isDefault}
                />
                <InstantSelectRow
                    slug={repository.slug}
                    field="default_responsible_user_id"
                    label="Default responsible"
                    description="Owns tasks that no person started, like Sentry alerts and CI scans."
                    value={repository.defaultResponsibleUserId ? String(repository.defaultResponsibleUserId) : ''}
                    options={[{ value: '', label: 'None' }, ...options.users]}
                />
                {isReadingConfig ? (
                    <div className="border-t border-hair py-3"><FieldSkeleton label="CI system" height="h-[34px]" /></div>
                ) : isCiSystemLocked ? (
                    <SettingRow
                        label="CI system"
                        description="Where Yak reads CI results after it pushes."
                        badge={<ConfigSourceBadge field="ciSystem" config={yakConfig} fileName="config.yml" />}
                    >
                        <span data-testid="locked-ciSystem" className="text-[13px] text-muted">
                            {options.ciSystems.find((system) => system.value === fileValues.ciSystem)?.label ?? fileValues.ciSystem}
                        </span>
                    </SettingRow>
                ) : (
                    <InstantSelectRow
                        slug={repository.slug}
                        field="ci_system"
                        label="CI system"
                        description="Where Yak reads CI results after it pushes a branch. Detected from the repo when you add it."
                        value={repository.ciSystem}
                        options={options.ciSystems}
                    />
                )}
                {isSentryListed && (
                    <InstantSelectRow
                        slug={repository.slug}
                        field="sentry_project"
                        label="Sentry project"
                        description="Maps incoming Sentry alerts for this project to this repository."
                        value={repository.sentryProject ?? ''}
                        options={[{ value: '', label: 'None' }, ...options.sentryProjects]}
                    />
                )}
            </SettingsCard>

            <SectionSaveBar isDirty={form.isDirty} processing={form.processing} onSave={save} onDiscard={discard} />
            {dialog}
        </RepositorySettingsLayout>
    );
}

General.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
