import { useState } from 'react';

export function PersonAvatar({ name, avatarUrl }: { name: string; avatarUrl?: string | null }) {
    const [failedUrl, setFailedUrl] = useState<string | null>(null);
    const initials = name
        .split(/\s+/)
        .filter((part) => part !== '')
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');

    if (avatarUrl && avatarUrl !== failedUrl) {
        return <img src={avatarUrl} alt="" referrerPolicy="no-referrer" onError={() => setFailedUrl(avatarUrl)} className="h-5 w-5 shrink-0 rounded-pill object-cover" />;
    }

    return (
        <span aria-hidden className="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-pill bg-app text-[10px] font-medium text-body">
            {initials}
        </span>
    );
}
