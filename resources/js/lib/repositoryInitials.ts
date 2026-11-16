/** Upper-case first two characters of the repository name (the part after the last slash). */
export function repositoryInitials(slug: string): string {
    return (slug.split('/').pop() ?? slug).slice(0, 2).toUpperCase();
}

/** Upper-case first character of the owner (the part before the slash), or of the name when there is no owner. */
export function repositoryOwnerInitial(slug: string): string {
    return slug.slice(0, 1).toUpperCase();
}
