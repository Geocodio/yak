const LABELS: Record<string, string> = {
    ts: 'TypeScript',
    tsx: 'TypeScript React',
    js: 'JavaScript',
    jsx: 'JavaScript React',
    mjs: 'JavaScript',
    json: 'JSON',
    php: 'PHP',
    md: 'Markdown',
    css: 'CSS',
    html: 'HTML',
    py: 'Python',
    go: 'Go',
    rb: 'Ruby',
    rs: 'Rust',
    sh: 'Shell',
    yml: 'YAML',
    yaml: 'YAML',
    sql: 'SQL',
    xml: 'XML',
};

export function languageLabel(path: string): string {
    const name = path.split('/').pop() ?? path;
    const dot = name.lastIndexOf('.');

    return dot > 0 ? (LABELS[name.slice(dot + 1).toLowerCase()] ?? 'Plain text') : 'Plain text';
}
