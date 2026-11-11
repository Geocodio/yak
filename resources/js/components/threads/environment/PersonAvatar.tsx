export function PersonAvatar({ name }: { name: string }) {
    const initials = name
        .split(/\s+/)
        .filter((part) => part !== '')
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');

    return (
        <span aria-hidden className="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-pill bg-app text-[10px] font-medium text-body">
            {initials}
        </span>
    );
}
