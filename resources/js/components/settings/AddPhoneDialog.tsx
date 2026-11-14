import { Button, Dialog, Spinner } from '@geocodio/console-ui';
import { AlertTriangle, Check, RefreshCw, Smartphone } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { csrfToken } from '@/lib/csrfToken';
import { fetchJsonWithRetry } from '@/lib/fetchJsonWithRetry';
import { show, store } from '@/routes/settings/devices/pairing-codes';
import type { Device } from '@/types/devices';

type PairingCode = { id: string; qrPayload: string; qrSvg: string; expiresAt: string };
type PairingState = { state: 'waiting' | 'paired' | 'expired'; device: Device | null };
type Phase = 'loading' | 'ready' | 'failed' | 'expired';

const POLL_INTERVAL_MS = 2000;

function formatCountdown(totalSeconds: number): string {
    return `${Math.floor(totalSeconds / 60)}:${String(totalSeconds % 60).padStart(2, '0')}`;
}

export function AddPhoneDialog({ onClose, onPaired }: { onClose: () => void; onPaired: () => void }) {
    const [phase, setPhase] = useState<Phase>('loading');
    const [code, setCode] = useState<PairingCode | null>(null);
    const [secondsLeft, setSecondsLeft] = useState(0);
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        setPhase('loading');
        setCode(null);

        fetch(store.url(), {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller.signal,
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json() as Promise<PairingCode>;
            })
            .then((created) => {
                setCode(created);
                setSecondsLeft(Math.max(0, Math.ceil((new Date(created.expiresAt).getTime() - Date.now()) / 1000)));
                setPhase('ready');
            })
            .catch(() => !controller.signal.aborted && setPhase('failed'));

        return () => controller.abort();
    }, [attempt]);

    useEffect(() => {
        if (phase !== 'ready' || code === null) {
            return;
        }

        const controller = new AbortController();
        const expiresAtMs = new Date(code.expiresAt).getTime();
        const tick = setInterval(() => {
            const remaining = Math.max(0, Math.ceil((expiresAtMs - Date.now()) / 1000));
            setSecondsLeft(remaining);

            if (remaining === 0) {
                setPhase('expired');
            }
        }, 1000);
        const poll = setInterval(() => {
            fetchJsonWithRetry<PairingState>(show.url(code.id), { signal: controller.signal })
                .then((result) => {
                    if (result.state === 'paired') {
                        onPaired();
                    } else if (result.state === 'expired') {
                        setPhase('expired');
                    }
                })
                .catch(() => undefined);
        }, POLL_INTERVAL_MS);

        return () => {
            controller.abort();
            clearInterval(tick);
            clearInterval(poll);
        };
    }, [phase, code, onPaired]);

    const retry = useCallback(() => setAttempt((value) => value + 1), []);

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && onClose()}
            title="Add a phone"
            description="Open the Yak app on your iPhone and scan this code. The phone is paired to your account at once."
            width="w-[min(560px,100vw)]"
            data-testid="add-phone-dialog"
            footer={<div className="flex justify-end"><Button variant="tertiary" onClick={onClose}>Close</Button></div>}
        >
            <div className="flex flex-col gap-4">
                {phase === 'loading' && (
                    <div role="status" className="flex items-center gap-2.5 text-[13px] text-muted" data-testid="pairing-loading"><Spinner size="sm" /> Making a pairing code</div>
                )}

                {phase === 'failed' && (
                    <div role="alert" className="flex flex-wrap items-center gap-3 rounded-card bg-panel-2 px-3.5 py-3 text-[13px]" data-testid="pairing-failed">
                        <AlertTriangle size={15} aria-hidden="true" />
                        <span className="flex-1">Yak could not make a pairing code. Nothing was changed. Try again in a moment.</span>
                        <Button variant="secondary" icon={<RefreshCw size={13} />} onClick={retry}>Try again</Button>
                    </div>
                )}

                {phase === 'expired' && (
                    <div role="status" className="flex flex-wrap items-center gap-3 rounded-card bg-panel-2 px-3.5 py-3 text-[13px]" data-testid="pairing-expired">
                        <span className="flex-1">This code has expired. Codes only work for two minutes.</span>
                        <Button variant="secondary" icon={<RefreshCw size={13} />} onClick={retry}>Show a new code</Button>
                    </div>
                )}

                {phase === 'ready' && code !== null && (
                    <div className="flex flex-wrap items-center gap-5">
                        <div
                            className="size-[200px] shrink-0 overflow-hidden rounded-card bg-white p-2 [&>svg]:size-full"
                            role="img"
                            aria-label="QR code for pairing your phone"
                            data-testid="pairing-qr"
                            data-qr-payload={code.qrPayload}
                            dangerouslySetInnerHTML={{ __html: code.qrSvg }}
                        />
                        <p className="min-w-[200px] flex-1 text-[13px] leading-relaxed text-muted">
                            The code works once and expires in <span className="font-medium text-ink" data-testid="pairing-countdown">{formatCountdown(secondsLeft)}</span>. Scan it only with your own phone.
                        </p>
                    </div>
                )}

                {(phase === 'ready' || phase === 'expired') && (
                    <div role="status" className="flex items-center gap-2.5 rounded-card bg-panel-2 px-3.5 py-3 text-[13px]" data-testid="pairing-waiting">
                        <Smartphone size={15} aria-hidden="true" />
                        <span className="font-medium">Waiting for a phone</span>
                        {phase === 'ready' ? <Spinner size="sm" /> : <Check size={14} className="opacity-0" aria-hidden="true" />}
                    </div>
                )}
            </div>
        </Dialog>
    );
}
