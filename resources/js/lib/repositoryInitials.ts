/** Upper-case first two characters of the repository name (the part after the last slash). */
export function repositoryInitials(slug: string): string {
    return (slug.split('/').pop() ?? slug).slice(0, 2).toUpperCase();
}
