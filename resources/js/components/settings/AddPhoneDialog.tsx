import { Button, Dialog, Spinner } from '@geocodio/console-ui';
import { AlertTriangle, RefreshCw, Smartphone } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { csrfToken } from '@/lib/csrfToken';
import { fetchJsonWithRetry } from '@/lib/fetchJsonWithRetry';
import { show, store } from '@/routes/settings/devices/pairing-codes';
import type { Device, PairingCode, PairingState } from '@/types/devices';

type Phase = 'loading' | 'ready' | 'failed' | 'expired';

const POLL_INTERVAL_MS = 2000;
const MAX_CONSECUTIVE_POLL_FAILURES = 3;

function formatCountdown(totalSeconds: number): string {
    return `${Math.floor(totalSeconds / 60)}:${String(totalSeconds % 60).padStart(2, '0')}`;
}

export function AddPhoneDialog({ onClose, onPaired }: { onClose: () => void; onPaired: (device: Device) => void }) {
    const [phase, setPhase] = useState<Phase>('loading');
    const [code, setCode] = useState<PairingCode | null>(null);
    const [secondsLeft, setSecondsLeft] = useState(0);
    const [receivedAtMs, setReceivedAtMs] = useState(0);
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
                setReceivedAtMs(Date.now());
                setSecondsLeft(created.expiresInSeconds);
                setPhase('ready');
            })
            .catch(() => !controller.signal.aborted && setPhase('failed'));

        return () => controller.abort();
    }, [attempt]);

    useEffect(() => {
        if (phase !== 'ready' || code === null) {
            return;
        }

        // usePoll reloads an Inertia page's props. This dialog watches a JSON endpoint that no page renders, so it polls with fetch and a timer.
        const controller = new AbortController();
        const deadlineMs = receivedAtMs + code.expiresInSeconds * 1000;
        let pollTimer: ReturnType<typeof setTimeout> | undefined;
        let failures = 0;
        let isFinished = false;

        const tick = setInterval(() => setSecondsLeft(Math.max(0, Math.ceil((deadlineMs - Date.now()) / 1000))), 1000);

        const poll = () => {
            fetchJsonWithRetry<PairingState>(show.url(code.id), { signal: controller.signal })
                .then((result) => {
                    failures = 0;

                    if (result.state === 'paired' && result.device !== null) {
                        isFinished = true;
                        onPaired(result.device);
                    } else if (result.state === 'expired') {
                        isFinished = true;
                        setPhase('expired');
                    } else if (Date.now() >= deadlineMs) {
                        // The final check after the countdown found the code still waiting on the server: stop waiting.
                        isFinished = true;
                        setPhase('expired');
                    }
                })
                .catch((failure: unknown) => {
                    if (controller.signal.aborted) {
                        return;
                    }

                    failures += 1;
                    const isClientError = failure instanceof Error && /\(4\d\d\)/.test(failure.message);

                    if (isClientError || failures >= MAX_CONSECUTIVE_POLL_FAILURES || Date.now() >= deadlineMs) {
                        isFinished = true;
                        setPhase('failed');
                    }
                })
                .finally(() => {
                    if (!isFinished && !controller.signal.aborted) {
                        pollTimer = setTimeout(poll, Math.min(POLL_INTERVAL_MS, Math.max(0, deadlineMs - Date.now())));
                    }
                });
        };

        pollTimer = setTimeout(poll, POLL_INTERVAL_MS);

        return () => {
            controller.abort();
            clearInterval(tick);
            clearTimeout(pollTimer);
        };
    }, [phase, code, receivedAtMs, onPaired]);

    const retry = useCallback(() => setAttempt((value) => value + 1), []);

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && onClose()}
            title="Add a phone"
            description="Open the Yak app on your phone or iPad and scan this code. The phone is paired to your account at once."
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

                {phase === 'ready' && (
                    <div role="status" className="flex items-center gap-2.5 rounded-card bg-panel-2 px-3.5 py-3 text-[13px]" data-testid="pairing-waiting">
                        <Smartphone size={15} aria-hidden="true" />
                        <span className="font-medium">Waiting for a phone</span>
                        <Spinner size="sm" />
                    </div>
                )}
            </div>
        </Dialog>
    );
}
