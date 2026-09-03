import { Head, useForm, usePoll } from '@inertiajs/react';
import { Badge, Button } from '@geocodio/console-ui';
import { Play } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRouterAction } from '@/lib/useRouterAction';
import { SettingsLayout } from '@/layouts/SettingsLayout';
import { ColorField } from '@/components/settings/ColorField';
import { FontPicker } from '@/components/settings/FontPicker';
import { LogoCard } from '@/components/settings/LogoCard';
import { LivePreview } from '@/components/settings/LivePreview';
import { destroy as destroyLogo } from '@/routes/settings/video/logo';
import video from '@/routes/settings/video';
import type { PageProps } from '@/types/shared';
import type { VideoThemeData } from '@/types/settings';

type Props = PageProps<{
    theme: VideoThemeData;
    sample: string | null;
    renderPending: boolean;
    previewAvailable: boolean;
}>;

const COLOR_FIELDS: { key: keyof VideoThemeData['colors']; label: string }[] = [
    { key: 'background', label: 'Background' },
    { key: 'surface', label: 'Chapter card' },
    { key: 'ink', label: 'Text' },
    { key: 'muted', label: 'Muted text' },
    { key: 'accent', label: 'Accent' },
    { key: 'done', label: 'Done' },
];

const FONT_ROLES: { role: keyof VideoThemeData['fonts']; label: string }[] = [
    { role: 'display', label: 'Display' },
    { role: 'body', label: 'Body' },
    { role: 'mono', label: 'Mono' },
];

type FormData = {
    colors: VideoThemeData['colors'];
    fonts: VideoThemeData['fonts'];
    logo: File | null;
};

export default function Video({ theme, sample, renderPending, previewAvailable }: Props) {
    const action = useRouterAction();
    // The download link only ever appears once the queued render finishes,
    // so the poll has to keep running until a sample exists rather than
    // stopping after one refresh.
    usePoll(10000, { only: ['sample'] }, { autoStart: sample === null && renderPending });

    const form = useForm<FormData>({
        colors: theme.colors,
        fonts: theme.fonts,
        logo: null,
    });

    const submit = () => {
        form.put(video.update.url(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.setData('logo', null),
        });
    };

    return (
        <>
            <Head title="Video walkthroughs" />

            <div className="grid items-start gap-8 lg:grid-cols-[420px_minmax(0,1fr)] lg:gap-9">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col gap-3">
                        <div className="flex items-center justify-between">
                            <h3 className="text-[13px] font-medium">Colors</h3>
                            <Button
                                variant="link"
                                className="text-[12px]"
                                pending={action.isPending('reset-theme')}
                                onClick={() => action.run('reset-theme', 'post', video.reset.url())}
                            >
                                Reset to defaults
                            </Button>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {COLOR_FIELDS.map(({ key, label }) => (
                                <ColorField
                                    key={key}
                                    name={`colors.${key}`}
                                    label={label}
                                    value={form.data.colors[key]}
                                    onChange={(v) => form.setData('colors', { ...form.data.colors, [key]: v })}
                                    error={form.errors[`colors.${key}`]}
                                />
                            ))}
                        </div>
                        <p className="text-[12px] text-faint">
                            Accent marks the focused element and the caption rule. Done is the checkmark color on the summary card.
                        </p>
                    </div>

                    <div className="flex flex-col gap-3">
                        <h3 className="text-[13px] font-medium">Fonts</h3>
                        <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-1">
                            {FONT_ROLES.map(({ role, label }) => (
                                <FontPicker
                                    key={role}
                                    role={role}
                                    label={label}
                                    value={form.data.fonts[role]}
                                    options={theme.fontFamilies}
                                    onChange={(family) => form.setData('fonts', { ...form.data.fonts, [role]: family })}
                                />
                            ))}
                        </div>
                        <p className="text-[12px] text-faint">Any Google Fonts family the renderer bundles. The renderer downloads it at render time.</p>
                    </div>

                    <div className="flex flex-col gap-3">
                        <h3 className="text-[13px] font-medium">Logo</h3>
                        <LogoCard
                            logoUrl={theme.logoUrl}
                            onSelect={(file) => form.setData('logo', file)}
                            onRemove={() => action.run('remove-logo', 'delete', destroyLogo.url())}
                        />
                        {form.errors.logo && <p className="text-[12px] text-fail">{form.errors.logo}</p>}
                    </div>

                    <div className="flex flex-col gap-3">
                        <h3 className="text-[13px] font-medium">Voiceover</h3>
                        <div className="flex items-center gap-3">
                            <Badge tone={theme.voiceoverEnabled ? 'ok' : 'neutral'}>{theme.voiceoverEnabled ? 'On' : 'Off'}</Badge>
                            <span className="text-[12px] text-faint">
                                {theme.voiceoverEnabled
                                    ? 'ElevenLabs key detected.'
                                    : 'Set ELEVENLABS_API_KEY to turn voiceover on. Without it, walkthroughs are captions only.'}
                            </span>
                        </div>
                    </div>

                    <div className="rounded-card border border-hair bg-panel-2 p-3 text-[12px] text-muted">
                        The repository&apos;s public site URL, not the sandbox address, is what shows in the browser bar in each video. Set it per
                        repository under Repositories, the repo, Public site URL.
                    </div>

                    <div className="flex items-center gap-3">
                        <Button variant="primary" pending={form.processing} onClick={submit}>
                            Save
                        </Button>
                        <Button
                            icon={<Play size={12} />}
                            pending={action.isPending('render-sample')}
                            pendingLabel="Queueing render…"
                            onClick={() => action.run('render-sample', 'post', video.sample.url())}
                        >
                            Render sample video
                        </Button>
                        {/* Sits outside any conditional: it is what makes the link appear once the queued render finishes. */}
                        <div data-testid="sample-render-status">
                            {sample && (
                                <a href={sample} className="text-[12px] underline">
                                    Download sample
                                </a>
                            )}
                        </div>
                        {theme.savedAt && <span className="ml-auto text-[12px] text-faint">Last saved {theme.savedAt}</span>}
                    </div>
                </div>

                <LivePreview
                    theme={{ colors: form.data.colors, fonts: form.data.fonts, logo: theme.logoUrl }}
                    googleFontsHref={theme.googleFontsHref}
                    fontPickerHref={theme.fontPickerHref}
                    previewAvailable={previewAvailable}
                />
            </div>
        </>
    );
}

Video.layout = (page: ReactNode) => <SettingsLayout slug="video">{page}</SettingsLayout>;
