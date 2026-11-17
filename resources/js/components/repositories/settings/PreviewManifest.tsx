import { Badge, Field, Skeleton, TextInput } from '@geocodio/console-ui';
import { ExpandableCodeField } from '@/components/editor/ExpandableCodeField';
import { shellLanguage } from '@/components/editor/shellLanguage';
import { ConfigSourceBadge, LockedField, LockedValue, ProposeChangeLink } from '@/components/repositories/ConfigSourceBadge';
import { SettingsCard } from '@/components/repositories/settings/SettingsCard';
import type { ManifestData, YakConfig } from '@/types/repositories';

const shellHighlighting = [shellLanguage()];

export type ManifestErrors = Partial<Record<'manifest.port' | 'manifest.health_probe_path' | 'manifest.wake_timeout_seconds', string>>;

/**
 * How a preview starts: editable fields bound to the page's form, or the
 * values of `.yak/preview.yml` shown read-only when the file sets them.
 */
export function PreviewManifest({
    manifest,
    errors,
    onChange,
    yakConfig,
}: {
    manifest: ManifestData;
    errors: ManifestErrors;
    onChange: (manifest: ManifestData) => void;
    yakConfig?: YakConfig;
}) {
    const fileManifest = yakConfig?.values.previewManifest ?? null;

    const setField = <K extends keyof ManifestData>(key: K, value: ManifestData[K]) => {
        onChange({ ...manifest, [key]: value });
    };

    if (yakConfig === undefined) {
        return <Skeleton className="mb-4 h-64 w-full rounded-card" />;
    }

    return (
        <SettingsCard
            title="How a preview starts"
            description="How the preview for this repository is built and served."
            testId="preview-manifest"
            aside={
                fileManifest ? (
                    <>
                        <ConfigSourceBadge field="previewManifest" config={yakConfig} fileName="preview.yml" />
                        <ProposeChangeLink config={yakConfig} fileName="preview.yml" />
                    </>
                ) : undefined
            }
        >
            <div className="flex flex-col gap-4">
                {fileManifest ? (
                    <LockedManifest
                        manifest={fileManifest}
                        fallback={{ port: manifest.port, health_probe_path: manifest.healthProbePath, cold_start: manifest.coldStart, checkout_refresh: manifest.checkoutRefresh, wake_timeout_seconds: manifest.wakeTimeoutSeconds }}
                        config={yakConfig}
                    />
                ) : (
                    <>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Port" description="Container port serving HTTP inside the preview." error={errors['manifest.port']}>
                                <TextInput type="number" value={manifest.port} onChange={(e) => setField('port', Number(e.target.value))} />
                            </Field>
                            <Field label="Health probe path" description="Path that returns a 2xx response once the app is ready to serve traffic." error={errors['manifest.health_probe_path']}>
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
                    </>
                )}
            </div>
        </SettingsCard>
    );
}

function LockedManifest({ manifest, fallback, config }: { manifest: Record<string, unknown>; fallback: Record<string, unknown>; config: YakConfig }) {
    const has = (key: string) => manifest[key] !== undefined && manifest[key] !== null;
    const show = (key: string) => {
        const fromFile = manifest[key];

        if (fromFile !== undefined && fromFile !== null) {
            return String(fromFile) === '' ? <span className="text-faint">(empty)</span> : String(fromFile);
        }

        return (
            <>
                {String(fallback[key] ?? '') === '' ? '' : String(fallback[key])} <span className="text-faint">(from Yak)</span>
            </>
        );
    };

    return (
        <>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <LockedField label="Port" field="manifestPort" config={config} hideBadge={!has('port')} fileName="preview.yml" mono>{show('port')}</LockedField>
                <LockedField label="Health probe path" field="manifestHealthProbePath" config={config} hideBadge={!has('health_probe_path')} fileName="preview.yml" mono>{show('health_probe_path')}</LockedField>
            </div>
            <LockedField label="Cold start command" field="manifestColdStart" config={config} hideBadge={!has('cold_start')} fileName="preview.yml" mono>{show('cold_start')}</LockedField>
            <div className="flex flex-col gap-1.5">
                <div className="flex flex-wrap items-center gap-2 text-[12px] font-medium">
                    Checkout refresh command
                    {config.values.previewScript && <Badge tone="neutral">Replaced by .yak/preview.sh</Badge>}
                </div>
                <LockedValue mono testId="locked-manifestCheckoutRefresh">{show('checkout_refresh')}</LockedValue>
                {config.values.previewScript && <p className="text-[12px] text-muted"><span className="font-mono">.yak/preview.sh</span> is committed, so deployments run it instead of this command.</p>}
            </div>
            <LockedField label="Wake timeout (seconds)" field="manifestWakeTimeout" config={config} hideBadge={!has('wake_timeout_seconds')} fileName="preview.yml" mono>{show('wake_timeout_seconds')}</LockedField>
        </>
    );
}
