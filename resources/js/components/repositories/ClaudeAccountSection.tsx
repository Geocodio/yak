import { useForm } from '@inertiajs/react';
import { Badge, Button, Field, Tooltip } from '@geocodio/console-ui';
import { AlertTriangle, Lock, Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { AccountMark } from '@/components/accounts/AccountMark';
import { AccountName } from '@/components/accounts/AccountName';
import { AccountPicker } from '@/components/accounts/AccountPicker';
import { KIND_LABEL, modelLabel } from '@/components/accounts/accountAppearance';
import { ModelPicker } from '@/components/accounts/ModelPicker';
import claudeAccount from '@/routes/repos/claude-account';
import unattendedClaudeAccount from '@/routes/repos/unattended-claude-account';
import { claudeAccounts as claudeAccountsPage } from '@/routes/settings';
import type { PickerAccount } from '@/types/claudeAccounts';
import type { RepositoryClaudeAccounts } from '@/types/repositories';

const pickerClass = 'h-8 w-full justify-start rounded-control border border-hair-strong bg-panel px-2.5 text-[13px] shadow-card hover:bg-panel-2';

function Section({ id, title, description, children }: { id: string; title: string; description: string; children: ReactNode }) {
    return (
        <section id={id} className="grid grid-cols-1 gap-4 border-b border-hair py-8 lg:grid-cols-[220px_1fr] lg:gap-8">
            <div>
                <h2 className="text-[13px] font-semibold">{title}</h2>
                <p className="mt-1 text-[12px] leading-relaxed text-muted">{description}</p>
            </div>
            <div className="flex flex-col gap-6">{children}</div>
        </section>
    );
}

function Alert({ tone, children }: { tone: 'warn' | 'fail'; children: ReactNode }) {
    return (
        <div className={`flex gap-2.5 rounded-control px-3 py-2.5 text-[12.5px] leading-snug ${tone === 'warn' ? 'bg-warn-soft' : 'bg-fail-soft'}`} role="alert">
            <AlertTriangle size={14} className={`mt-0.5 shrink-0 ${tone === 'warn' ? 'text-warn' : 'text-fail'}`} aria-hidden="true" />
            <div>{children}</div>
        </div>
    );
}

/**
 * Your account and model for this repository, and the Yak account unattended work runs on.
 * Both save on their own, independent of the "Save repository" button.
 */
export function ClaudeAccountSection({ slug, data }: { slug: string; data: RepositoryClaudeAccounts }) {
    const mine = data.accounts.filter((account) => !account.isSystem);
    const yakAccounts = data.accounts.filter((account) => account.isSystem);
    const mineForm = useForm({ claude_account_id: data.mySetting.accountId === null ? '' : String(data.mySetting.accountId), model: data.mySetting.model ?? '' });
    const unattendedForm = useForm({ claude_account_id: data.unattendedAccountId === null ? '' : String(data.unattendedAccountId) });

    const overallDefault = mine.find((account) => account.isDefault && (account.usable ?? true));
    const globalYak = yakAccounts.find((account) => account.id === data.globalSystemAccountId);
    const chosen = mine.find((account) => String(account.id) === mineForm.data.claude_account_id) ?? overallDefault;
    const brokenChoice = chosen && !(chosen.usable ?? chosen.status === 'active') ? chosen : null;
    const working: PickerAccount | undefined = (brokenChoice ? overallDefault : chosen) ?? globalYak;
    const effectiveModel = mineForm.data.model || working?.defaultModel || data.models[0]?.family || 'sonnet';

    const unattended = yakAccounts.find((account) => String(account.id) === unattendedForm.data.claude_account_id) ?? globalYak;

    const saveMine = (next: { claude_account_id: string; model: string }) => {
        mineForm.transform(() => ({ claude_account_id: next.claude_account_id === '' ? null : Number(next.claude_account_id), model: next.model || null }));
        mineForm.setData(next);
        mineForm.put(claudeAccount.update.url(slug), { preserveScroll: true });
    };
    const saveUnattended = (id: string) => {
        unattendedForm.transform(() => ({ claude_account_id: id === '' ? null : Number(id) }));
        unattendedForm.setData('claude_account_id', id);
        unattendedForm.put(unattendedClaudeAccount.update.url(slug), { preserveScroll: true });
    };

    const saveFailed = mineForm.hasErrors || unattendedForm.hasErrors;
    const failureMessage = mineForm.errors.claude_account_id ?? mineForm.errors.model ?? unattendedForm.errors.claude_account_id;

    return (
        <>
            <Section id="claude-account" title="Your Claude account for this repo" description="Which of your Claude accounts runs the tasks you start here. Only you see and use this setting. Teammates pick their own.">
                {saveFailed && (
                    <Alert tone="fail">
                        <span className="font-medium">Could not save this setting.</span> {failureMessage} Your choice is still here, pick it again to retry.
                    </Alert>
                )}
                {mine.length === 0 ? (
                    <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 rounded-card border border-hair bg-panel px-4 py-3 shadow-card" data-testid="no-accounts">
                        <div className="max-w-[460px]">
                            <div className="text-[13px] font-medium">You have no Claude account yet</div>
                            <p className="mt-0.5 text-[12px] text-muted">Tasks you start here run on the shared Yak account{globalYak ? ` (${globalYak.name})` : ''} until you add your own.</p>
                        </div>
                        <a href={claudeAccountsPage.url()}>
                            <Button variant="secondary" icon={<Plus size={14} />}>Add an account</Button>
                        </a>
                    </div>
                ) : (
                    <>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Account">
                                <AccountPicker
                                    label="Account"
                                    className={pickerClass}
                                    value={mineForm.data.claude_account_id}
                                    onChange={(id) => saveMine({ ...mineForm.data, claude_account_id: id })}
                                    accounts={mine}
                                    defaultLabel={overallDefault ? `My default (${overallDefault.name})` : 'My default'}
                                    defaultAccount={overallDefault}
                                    disabled={mineForm.processing}
                                />
                            </Field>
                            <Field label="Model">
                                <ModelPicker
                                    models={data.models}
                                    value={mineForm.data.model}
                                    onChange={(value) => saveMine({ ...mineForm.data, model: value })}
                                    defaultLabel={`Account default (${modelLabel(working?.defaultModel ?? data.models[0]?.family ?? null, data.models)})`}
                                    testId="repo-model"
                                />
                            </Field>
                        </div>
                        {brokenChoice && (
                            <Alert tone="warn">
                                <span className="font-medium">{brokenChoice.name} needs a new secret.</span> Until you replace it, tasks you start here run on {overallDefault ? `your default account, ${overallDefault.name}` : 'the shared Yak account'}.{' '}
                                <a href={claudeAccountsPage.url()} className="text-accent-text hover:underline">Replace the secret</a>
                            </Alert>
                        )}
                        {working && (
                            <p className="text-[12px] leading-relaxed text-muted" data-testid="resolved">
                                Tasks you start here run on <AccountName account={working} className="align-middle font-medium text-body" /> with <span className="font-medium text-body">{modelLabel(effectiveModel, data.models)}</span>. You can pick a different account or model for one task in the new task form. A task keeps its account for its whole life.{' '}
                                <a href={data.docsUrl} target="_blank" rel="noopener noreferrer" className="text-accent-text hover:underline">How accounts work</a>
                            </p>
                        )}
                    </>
                )}
            </Section>

            <Section id="unattended-account" title="Unattended work" description="Which Yak account runs work that nobody started on this repository.">
                {data.canChangeUnattended ? (
                    <>
                        <Field label="Yak account" description="Sentry alerts, Linear automation and flaky test fixes run on this account until a person replies. A thread stays on it after that.">
                            <AccountPicker
                                label="Yak account"
                                className={`${pickerClass} sm:max-w-[360px]`}
                                value={unattendedForm.data.claude_account_id}
                                onChange={saveUnattended}
                                accounts={yakAccounts}
                                defaultLabel={globalYak ? `Global default (${globalYak.name})` : 'Global default'}
                                defaultAccount={globalYak}
                                disabled={unattendedForm.processing}
                            />
                        </Field>
                        {unattended && (
                            <p className="text-[12px] text-muted">
                                Currently runs on <AccountName account={unattended} className="align-middle font-medium text-body" /> with {modelLabel(unattended.defaultModel, data.models)}. Add or replace Yak accounts in{' '}
                                <a href={claudeAccountsPage.url()} className="text-accent-text hover:underline">Claude accounts</a>.
                            </p>
                        )}
                    </>
                ) : (
                    <div className="flex flex-col gap-2" data-testid="unattended-readonly">
                        <div className="flex flex-wrap items-center gap-3 rounded-card border border-hair bg-panel-2 px-4 py-3">
                            {unattended ? (
                                <>
                                    <AccountMark appearance={unattended} size={32} />
                                    <div className="min-w-0 flex-1">
                                        <div className="text-[13px] font-medium">
                                            {unattended.name}
                                            {data.unattendedAccountId === null && <span className="ml-2 text-[12px] font-normal text-muted">Global default</span>}
                                        </div>
                                        <div className="text-[12px] text-muted">{KIND_LABEL[unattended.kind]} · {modelLabel(unattended.defaultModel, data.models)}</div>
                                    </div>
                                </>
                            ) : (
                                <div className="min-w-0 flex-1 text-[13px] text-muted">No Yak account is set up yet.</div>
                            )}
                            <Tooltip label="Only Yak admins can change which Yak account unattended work runs on." side="top">
                                <span tabIndex={0} className="inline-flex rounded-pill focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                                    <Badge tone="neutral"><Lock size={10} className="mr-1 inline" aria-hidden="true" />Admins only</Badge>
                                </span>
                            </Tooltip>
                        </div>
                        <p className="text-[12px] leading-relaxed text-muted">Sentry alerts, Linear automation and flaky test fixes run on this account. Ask a Yak admin if it should change.</p>
                    </div>
                )}
            </Section>
        </>
    );
}
