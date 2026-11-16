import { Head, router } from '@inertiajs/react';
import { Button, cn } from '@geocodio/console-ui';
import { AlertTriangle, Bell, Share, Smartphone } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { Card } from '@/components/accounts/AccountsSection';
import { BlockedHelp } from '@/components/settings/notifications/BlockedHelp';
import { DeviceList } from '@/components/settings/notifications/DeviceList';
import { EventToggles } from '@/components/settings/notifications/EventToggles';
import { InstallCard } from '@/components/settings/notifications/InstallCard';
import { IosSteps } from '@/components/settings/notifications/IosSteps';
import { SettingsLayout } from '@/layouts/SettingsLayout';
import { usePlatform } from '@/pwa/usePlatform';
import { usePushDevice } from '@/pwa/usePushDevice';
import { notifications } from '@/routes/settings';
import type { NotificationSettings } from '@/types/notifications';
import type { PageProps } from '@/types/shared';

type Props = PageProps<NotificationSettings>;

const Intro = ({ children }: { children: ReactNode }) => <p className="mt-1 max-w-[600px] text-[12.5px] leading-relaxed text-muted">{children}</p>;

function deviceLabel(isStandalone: boolean): string {
    const agent = navigator.userAgent;
    const browser = /Edg\//.test(agent) ? 'Edge' : /Firefox/.test(agent) ? 'Firefox' : /Chrome/.test(agent) ? 'Chrome' : /Safari/.test(agent) ? 'Safari' : 'Browser';
    const system = /iPhone/.test(agent) ? 'iPhone' : /iPad/.test(agent) ? 'iPad' : /Android/.test(agent) ? 'Android' : /Mac/.test(agent) ? 'macOS' : /Windows/.test(agent) ? 'Windows' : /Linux/.test(agent) ? 'Linux' : '';

    if (isStandalone) {
        return `${system === '' ? browser : system}, installed app`;
    }

    return system === '' ? browser : `${browser} on ${system}`;
}

export default function Notifications({ events, devices, vapidPublicKey, isIosSupported }: Props) {
    const platform = usePlatform();
    const push = usePushDevice(vapidPublicKey);
    const [showIosSteps, setShowIosSteps] = useState(false);
    const [subscribeError, setSubscribeError] = useState<string | null>(null);

    const hasCurrentDevice = devices.some((device) => device.isCurrent);

    // The server marks the current device from the endpoint this browser holds.
    useEffect(() => {
        if (push.endpoint !== null && !hasCurrentDevice && devices.length > 0 && !window.location.search.includes('endpoint=')) {
            router.get(notifications.url({ query: { endpoint: push.endpoint } }), {}, { only: ['devices'], replace: true, preserveState: true, preserveScroll: true });
        }
    }, [push.endpoint, hasCurrentDevice, devices.length]);

    const isIosGate = platform.isIos && !platform.isStandalone;
    const isBlocked = platform.permission === 'denied';
    const isUnconfigured = vapidPublicKey === null;
    const isGated = isIosGate || isBlocked || isUnconfigured || !platform.isPushSupported;
    const showEnable = !isGated && !hasCurrentDevice;

    const enable = async () => {
        setSubscribeError(null);
        const result = await push.subscribe(deviceLabel(platform.isStandalone));
        platform.refresh();

        if (result === 'failed' || result === 'unsupported') {
            setSubscribeError('Yak could not turn on notifications in this browser. Try again, or use another browser.');
        }
    };

    return (
        <>
            <Head title="Notifications" />

            <div className="flex flex-col gap-6">
                <div id="push-heading">
                    <Intro>Yak can tap your phone or desktop when a thread needs you. Slack and email notifications work as before. Nothing is pushed while you have that thread open.</Intro>
                </div>

                {isUnconfigured && (
                    <Card className="p-4" data-testid="gate-unconfigured">
                        <div className="flex gap-3">
                            <span className="grid size-9 shrink-0 place-items-center rounded-pill bg-warn-soft text-warn" aria-hidden="true"><AlertTriangle size={17} /></span>
                            <div className="min-w-0">
                                <h4 className="text-[13.5px] font-semibold">Push is not set up on this server</h4>
                                <p className="mt-1 text-[12.5px] leading-relaxed text-muted">The server has no VAPID keys, so it cannot send push notifications. Ask whoever runs Yak to generate them with <code className="font-mono">php artisan webpush:vapid</code>.</p>
                            </div>
                        </div>
                    </Card>
                )}

                {!isUnconfigured && isIosGate && (isIosSupported ? (
                    <Card className="p-4" data-testid="gate-ios">
                        <div className="flex gap-3">
                            <span className="grid size-9 shrink-0 place-items-center rounded-pill bg-info-soft text-info" aria-hidden="true"><Smartphone size={17} /></span>
                            <div className="min-w-0">
                                <h4 className="text-[13.5px] font-semibold">iPhone only allows push from an installed app</h4>
                                <p className="mt-1 text-[12.5px] leading-relaxed text-muted">Safari tabs cannot receive notifications on iOS. Add Yak to your Home Screen, open it from there, and come back to this page to turn notifications on. It takes about 20 seconds.</p>
                                <div className="mt-3"><Button variant="primary" icon={<Share size={13} />} onClick={() => setShowIosSteps(true)}>Show me how</Button></div>
                            </div>
                        </div>
                    </Card>
                ) : <IosSteps isSupported={false} />)}

                {!isUnconfigured && !isIosGate && isBlocked && <BlockedHelp onCheckAgain={() => window.location.reload()} />}

                {showEnable && (
                    <Card className="p-5 text-center" data-testid="gate-off">
                        <span className="mx-auto grid size-11 place-items-center rounded-pill bg-brand-soft text-brand" aria-hidden="true"><Bell size={20} /></span>
                        <h4 className="mt-3 text-[14px] font-semibold">Turn on notifications on this device</h4>
                        <p className="mx-auto mt-1 max-w-[440px] text-[12.5px] leading-relaxed text-muted">Your browser will ask for permission. The four kinds below start switched on. You can pause any device from the list further down.</p>
                        {subscribeError !== null && <p role="alert" className="mt-2 text-[12.5px] text-fail">{subscribeError}</p>}
                        <Button className="mt-4" variant="primary" icon={<Bell size={14} />} onClick={() => void enable()} data-testid="enable-push">Turn on for this device</Button>
                    </Card>
                )}

                <section className={cn('flex flex-col gap-3', isGated && 'opacity-55')} aria-labelledby="events-heading">
                    <div>
                        <h3 id="events-heading" className="text-[14px] font-semibold">What to push</h3>
                        <Intro>These choices apply to every device you have turned notifications on for. Pause a single device in the list below.</Intro>
                    </div>
                    <EventToggles events={events} isDisabled={false} />
                </section>

                {devices.length > 0 && (
                    <section className="flex flex-col gap-3" aria-labelledby="devices-heading">
                        <div>
                            <h3 id="devices-heading" className="text-[14px] font-semibold">Your devices</h3>
                            <Intro>Each browser or installed app you turned notifications on for. Pause one to stop pushes to it for now, or remove it.</Intro>
                        </div>
                        <DeviceList devices={devices} />
                    </section>
                )}

                <section id="install-section" className="flex scroll-mt-4 flex-col gap-4 border-t border-hair pt-6" aria-label="Install the app">
                    <div>
                        <h3 className="text-[14px] font-semibold">Install Yak as an app</h3>
                        <Intro>Installed, Yak opens in its own window with its own icon, and pushes notifications to your phone. On iPhone and iPad this is required for notifications.</Intro>
                    </div>
                    {showIosSteps || (platform.isIos && !platform.isStandalone) ? <IosSteps isSupported={isIosSupported} /> : null}
                    {platform.isIos && <a href="#ios-steps-heading" className="text-[12.5px] text-accent-text hover:underline" data-testid="ios-why-link">Why iOS needs this</a>}
                    <InstallCard isStandalone={platform.isStandalone} isIos={platform.isIos} onShowIosSteps={() => setShowIosSteps(true)} />
                </section>
            </div>
        </>
    );
}

Notifications.layout = (page: ReactNode) => <SettingsLayout slug="notifications">{page}</SettingsLayout>;
