export const OPERATING_SYSTEMS: Record<string, string> = { darwin: 'macOS', linux: 'Linux' };

export function systemLabel(os: string, arch: string): string {
    const archLabel = os === 'darwin' && arch === 'arm64' ? 'Apple silicon' : arch;

    return `${OPERATING_SYSTEMS[os] ?? os}, ${archLabel}`;
}
