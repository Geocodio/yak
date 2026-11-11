import { Head, router } from '@inertiajs/react';
import { BrandMarkIcon, Button, TextInput, cn } from '@geocodio/console-ui';
import { Check, Laptop, ShieldAlert, TimerOff } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { YAK_MARK } from '@/lib/brand';
import { approve, deny, show } from '@/routes/device';
import type { DeviceApproval } from '@/types/devices';

const OPERATING_SYSTEMS = { darwin: 'macOS', linux: 'Linux' } as const;

function systemLabel({ os, arch }: Pick<DeviceApproval, 'os' | 'arch'>): string {
    const system = os === null ? 'Unknown' : OPERATING_SYSTEMS[os];

    return arch === null ? system : `${system}, ${arch}`;
}

function askedLabel(requestedAt: string | null): string {
    if (requestedAt === null) {
        return 'Unknown';
    }

    const minutes = Math.round((Date.now() - new Date(requestedAt).getTime()) / 60000);

    return minutes < 1 ? 'Just now' : `${minutes} min ago`;
}

function Facts({ device, name, onName }: { device: DeviceApproval; name: string; onName?: (name: string) => void }) {
    const rows: [string, string][] = [
        ['System', systemLabel(device)],
        ['Plugin', device.cli_version === null ? 'yak' : `yak ${device.cli_version}`],
        ['Asked', askedLabel(device.requested_at)],
    ];

    return (
        <>
            <p className="mb-1.5 text-[12px] text-muted">Reported by the computer, so it is only a hint. The code is what proves it is yours.</p>
            <dl className="grid grid-cols-[88px_1fr] items-center gap-x-3 gap-y-1.5 text-[13px]">
                <dt className="text-muted"><label htmlFor="device-name">Name</label></dt>
                <dd className="min-w-0">
                    {onName ? <TextInput id="device-name" maxLength={100} value={name} onChange={(event) => onName(event.target.value)} /> : name}
                </dd>
                {rows.map(([key, value]) => <div key={key} className="contents"><dt className="text-muted">{key}</dt><dd className="min-w-0">{value}</dd></div>)}
            </dl>
        </>
    );
}

function Icon({ tone, children }: { tone: 'ok' | 'fail' | 'warn' | 'neutral'; children: ReactNode }) {
    const tones = { ok: 'bg-ok-soft text-ok', warn: 'bg-warn-soft text-warn', fail: 'bg-fail-soft text-fail', neutral: 'bg-panel-2 text-muted' };

    return <span className={cn('mx-auto mb-3 grid size-11 place-items-center rounded-pill', tones[tone])} aria-hidden="true">{children}</span>;
}

function EnterAgain({ label }: { label: string }) {
    return <div className="mt-5 flex justify-center"><Button variant="secondary" onClick={() => router.get(show.url())}>{label}</Button></div>;
}

function EnterCode({ device }: { device: DeviceApproval }) {
    const [code, setCode] = useState(device.code ?? '');
    const error = device.state === 'enter-error';
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(show.url(), { code });
    };

    return (
        <>
            <h1 className="text-center text-[18px] font-semibold tracking-tight" data-testid="device-state">Connect a computer</h1>
            <p className="mt-1.5 text-center text-[13px] leading-relaxed text-muted">Type the code that <code className="font-mono text-[12px]">yak login</code> shows in your terminal.</p>
            <form className="mt-5 flex flex-col gap-3" onSubmit={submit}>
                <div className="[&>*:first-child]:w-full">
                    <TextInput aria-label="Code from your terminal" aria-invalid={error} aria-describedby="code-help" className="h-11 w-full text-center font-mono text-[18px] uppercase tracking-[0.2em]" value={code} onChange={(event) => setCode(event.target.value)} placeholder="XXXX-XXXX" autoFocus />
                    <p id="code-help" className={cn('mt-1.5 text-[12px] leading-relaxed', error ? 'text-fail' : 'text-muted')}>{error ? 'That code does not match a waiting computer. Check it against your terminal, or run yak login again for a new one.' : 'Codes are 8 characters and work for 10 minutes.'}</p>
                </div>
                <Button variant="primary" type="submit" className="h-9 justify-center" disabled={code.trim().length < 8}>Continue</Button>
            </form>
        </>
    );
}

function Confirm({ device }: { device: DeviceApproval }) {
    const [name, setName] = useState(device.name ?? '');
    const code = device.code ?? '';

    return (
        <>
            <Icon tone="neutral"><Laptop size={20} /></Icon>
            <h1 className="text-center text-[18px] font-semibold tracking-tight" data-testid="device-state">Connect this computer to Yak?</h1>
            <p className="mt-1.5 text-center text-[13px] leading-relaxed text-muted">Check that this code is the one in your terminal.</p>
            <div className="mt-4 rounded-card bg-panel-2 py-4 text-center" aria-label={`Code ${code}`}><span className="font-mono text-[28px] font-semibold tracking-[0.18em]" data-testid="device-code">{code}</span></div>
            <div className="mt-4"><Facts device={device} name={name} onName={setName} /></div>
            <p className="mt-4 text-[12.5px] leading-relaxed text-muted">Approving lets this computer teleport your threads to and from Yak, as you. You can revoke it any time in Settings, Devices.</p>
            <div className="mt-5 flex flex-col gap-2 sm:flex-row-reverse">
                <Button variant="primary" className="min-h-10 w-full justify-center sm:flex-1" data-testid="device-approve" onClick={() => router.post(approve.url(), { code, name })}>Approve this computer</Button>
                <Button variant="secondary" className="min-h-10 w-full justify-center sm:flex-1" data-testid="device-deny" onClick={() => router.post(deny.url(), { code })}>This is not me</Button>
            </div>
            <p className="mt-4 text-center text-[12px] text-faint">Signed in as {device.email}</p>
        </>
    );
}

function Body({ device }: { device: DeviceApproval }) {
    switch (device.state) {
        case 'confirm':
            return <Confirm device={device} />;
        case 'approved':
            return (
                <div className="text-center">
                    <Icon tone="ok"><Check size={22} /></Icon>
                    <h1 className="text-[18px] font-semibold tracking-tight" data-testid="device-state">Computer connected</h1>
                    <p className="mt-1.5 text-[13px] leading-relaxed text-muted">Go back to your terminal. <code className="font-mono text-[12px]">yak login</code> finishes by itself, and you can close this tab.</p>
                    <div className="mt-4 text-left"><Facts device={device} name={device.name ?? ''} /></div>
                </div>
            );
        case 'denied':
            return (
                <div className="text-center">
                    <Icon tone="fail"><ShieldAlert size={20} /></Icon>
                    <h1 className="text-[18px] font-semibold tracking-tight" data-testid="device-state">Nothing was connected</h1>
                    <p className="mt-1.5 text-[13px] leading-relaxed text-muted">The request was denied and that code no longer works. If you did not run <code className="font-mono text-[12px]">yak login</code>, someone may have typed your code by guessing. Nothing on your account changed.</p>
                    <EnterAgain label="Enter a different code" />
                </div>
            );
        case 'expired':
            return (
                <div className="text-center">
                    <Icon tone="warn"><TimerOff size={20} /></Icon>
                    <h1 className="text-[18px] font-semibold tracking-tight" data-testid="device-state">This code expired</h1>
                    <p className="mt-1.5 text-[13px] leading-relaxed text-muted">Codes work for 10 minutes. Run <code className="rounded-[4px] bg-panel-2 px-1 py-px font-mono text-[12px] text-body">yak login</code> in your terminal again and use the new code.</p>
                    <EnterAgain label="Enter a new code" />
                </div>
            );
        default:
            return <EnterCode device={device} />;
    }
}

export default function Approve(device: DeviceApproval) {
    return (
        <div className="flex min-h-dvh flex-col bg-app">
            <Head title="Connect a computer" />
            <header className="flex h-12 shrink-0 items-center gap-2 border-b border-hair px-4"><BrandMarkIcon mark={YAK_MARK} size={22} /><span className="text-[13px] font-medium">Yak</span></header>
            <main className="flex flex-1 flex-col items-center gap-5 px-4 py-8 sm:py-14">
                <div className="w-full max-w-[420px] rounded-card border border-hair bg-panel p-5 shadow-card sm:p-6"><Body device={device} /></div>
                <p className="max-w-[420px] text-center text-[12px] leading-relaxed text-faint">Only approve a code you just saw in your own terminal.</p>
            </main>
        </div>
    );
}
