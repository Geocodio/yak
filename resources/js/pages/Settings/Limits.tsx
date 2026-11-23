import { Deferred, Head, useForm } from '@inertiajs/react';
import { Button, TextInput, Toggle } from '@geocodio/console-ui';
import { useEffect, useMemo, type ReactNode } from 'react';
import { Card } from '@/components/accounts/AccountsSection';
import { CapacitySuggestionCard, CapacitySuggestionSkeleton } from '@/components/settings/limits/CapacitySuggestionCard';
import { useRouterAction } from '@/lib/useRouterAction';
import { SettingsLayout } from '@/layouts/SettingsLayout';
import limitsRoutes from '@/routes/settings/limits';
import type { CapacitySuggestion, LimitGroup, LimitSetting } from '@/types/limits';
import type { PageProps } from '@/types/shared';

type Props = PageProps<{
    groups: LimitGroup[];
    suggestion?: CapacitySuggestion;
}>;

type FormValues = Record<string, string | boolean>;

const toFormValues = (groups: LimitGroup[]): FormValues =>
    Object.fromEntries(groups.flatMap((group) => group.settings).map((setting) => [setting.id, setting.type === 'bool' ? Boolean(setting.value) : String(setting.value)]));

function formatDefault(setting: LimitSetting): string {
    if (setting.type === 'bool') {
        return setting.default ? 'On' : 'Off';
    }
    if (setting.unit === 'USD') {
        return `$${setting.default}`;
    }

    return `${setting.default}${setting.unit ? ` ${setting.unit}` : ''}`;
}

function SettingRow({ setting, value, error, onChange }: { setting: LimitSetting; value: string | boolean; error?: string; onChange: (value: string | boolean) => void }) {
    const isChanged = setting.type === 'bool' ? value !== setting.default : value !== String(setting.default);
    const describedBy = error ? `${setting.id}-error` : undefined;

    return (
        <div className="grid gap-x-4 gap-y-2 px-3.5 py-3 sm:grid-cols-[1fr_auto] sm:items-center" data-testid={`limit-${setting.id}`}>
            <div className="min-w-0">
                <div className="font-medium">{setting.label}</div>
                {setting.help ? <div className="text-[12px] text-muted">{setting.help}</div> : null}
                {setting.type !== 'bool' ? (
                    <div className="text-[11px] text-muted">
                        Default {formatDefault(setting)}
                        {isChanged ? <span className="ml-1.5 font-medium text-accent-text">Changed</span> : null}
                    </div>
                ) : null}
                {error ? <p id={describedBy} role="alert" className="mt-1 text-[12px] text-fail">{error}</p> : null}
            </div>
            <div className="flex items-center gap-1.5 sm:justify-end">
                {setting.type === 'bool' ? (
                    <Toggle checked={value === true} onCheckedChange={onChange} label={setting.label} />
                ) : (
                    <>
                        <TextInput
                            value={String(value)}
                            onChange={(event) => onChange(event.target.value)}
                            inputMode={setting.type === 'decimal' ? 'decimal' : 'numeric'}
                            aria-label={setting.label}
                            aria-invalid={error ? true : undefined}
                            aria-describedby={describedBy}
                            className="w-20 text-right tabular-nums"
                        />
                        <span className="min-w-[34px] text-[12px] text-muted">{setting.unit}</span>
                    </>
                )}
            </div>
        </div>
    );
}

export default function Limits({ groups, suggestion }: Props) {
    const action = useRouterAction();
    const initial = useMemo(() => toFormValues(groups), [groups]);
    const form = useForm<{ values: FormValues }>({ values: initial });
    const settings = groups.flatMap((group) => group.settings);
    const hasOverrides = settings.some((setting) => setting.isOverridden);
    const memoryFloorGb = Number(settings.find((setting) => setting.id === 'memory_floor_gb')?.value ?? 0);
    const initialKey = JSON.stringify(initial);

    // A save, a reset or the suggestion card changes the stored values; the form follows them.
    useEffect(() => {
        form.setData('values', initial);
        form.setDefaults();
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [initialKey]);

    const save = () => {
        form.transform((data) => ({
            values: Object.fromEntries(Object.entries(data.values).map(([id, value]) => [id, typeof value === 'boolean' ? value : value.trim()])),
        }));
        form.put(limitsRoutes.update.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Limits" />

            <form className="flex flex-col gap-7 text-[13px]" onSubmit={(event) => { event.preventDefault(); save(); }}>
                {groups.map((group) => (
                    <section key={group.id} className="flex flex-col gap-2" aria-labelledby={`limits-${group.id}`} data-testid={`limits-group-${group.id}`}>
                        <h3 id={`limits-${group.id}`} className="text-[14px] font-semibold">{group.title}</h3>
                        {group.description ? <p className="text-[12px] text-muted">{group.description}</p> : null}

                        {group.id === 'sandboxes' ? (
                            <Deferred data="suggestion" fallback={<CapacitySuggestionSkeleton />}>
                                {suggestion ? <CapacitySuggestionCard suggestion={suggestion} memoryFloorGb={memoryFloorGb} /> : null}
                            </Deferred>
                        ) : null}

                        <Card className="divide-y divide-hair overflow-hidden">
                            {group.settings.map((setting) => (
                                <SettingRow
                                    key={setting.id}
                                    setting={setting}
                                    value={form.data.values[setting.id] ?? ''}
                                    error={(form.errors as Record<string, string>)[`values.${setting.id}`]}
                                    onChange={(value) => form.setData('values', { ...form.data.values, [setting.id]: value })}
                                />
                            ))}
                        </Card>
                    </section>
                ))}

                <div className="flex items-center gap-1.5">
                    <Button type="submit" variant="primary" pending={form.processing} disabled={!form.isDirty} data-testid="limits-save">Save changes</Button>
                    <Button
                        type="button"
                        variant="link"
                        disabled={!hasOverrides}
                        pending={action.isPending('reset-limits')}
                        onClick={() => action.run('reset-limits', 'post', limitsRoutes.reset.url())}
                    >
                        Reset all to defaults
                    </Button>
                </div>

                <p className="text-[12px] text-muted">
                    Secrets, addresses, the Incus setup, timeouts and polling intervals stay in the server&rsquo;s environment. A value saved here replaces the one from the environment. &ldquo;Default&rdquo; shows the value from the environment.
                </p>
            </form>
        </>
    );
}

Limits.layout = (page: ReactNode) => <SettingsLayout slug="limits">{page}</SettingsLayout>;
