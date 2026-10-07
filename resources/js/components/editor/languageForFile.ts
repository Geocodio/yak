import { StreamLanguage, type StreamParser } from '@codemirror/language';

type Detected = { label: string; parser: () => Promise<StreamParser<unknown>> };

const PHP_KEYWORDS =
    'abstract and as break case catch class clone const continue declare default do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile enum extends final finally fn for foreach function global goto if implements include include_once instanceof insteadof interface isset list match namespace new or print private protected public readonly require require_once return static switch throw trait try unset use var while xor yield';

/**
 * PHP has no legacy mode of its own, so this builds one from the C-like
 * parser: PHP keywords, `$variables`, `#` and `//` comments, `<?php` tags
 * and backslashed namespaces. Enough to read a snippet or a stack trace.
 */
async function php(): Promise<StreamParser<unknown>> {
    const { clike } = await import('@codemirror/legacy-modes/mode/clike');
    const words = (list: string) => Object.fromEntries(list.split(' ').map((word) => [word, true]));

    return clike({
        name: 'php',
        keywords: words(PHP_KEYWORDS),
        blockKeywords: words('catch do else elseif for foreach if switch try while finally match'),
        atoms: words('true false null TRUE FALSE NULL self parent'),
        builtin: words('array string int float bool mixed void never object iterable callable'),
        multiLineStrings: true,
        namespaceSeparator: '\\',
        hooks: {
            $: (stream: { eatWhile: (match: RegExp) => boolean }) => {
                stream.eatWhile(/[\w$]/);
                return 'variable-2';
            },
            '#': (stream: { skipToEnd: () => void }) => {
                stream.skipToEnd();
                return 'comment';
            },
            '<': (stream: { match: (pattern: string) => boolean }) => (stream.match('?php') || stream.match('?=') ? 'meta' : false),
            '?': (stream: { match: (pattern: string) => boolean }) => (stream.match('>') ? 'meta' : false),
        },
    });
}

const BY_EXTENSION: Record<string, Detected> = {};

function register(extensions: string[], label: string, parser: () => Promise<StreamParser<unknown>>): void {
    extensions.forEach((extension) => (BY_EXTENSION[extension] = { label, parser }));
}

register(['js', 'mjs', 'cjs', 'jsx'], 'JavaScript', async () => (await import('@codemirror/legacy-modes/mode/javascript')).javascript);
register(['ts', 'mts', 'cts', 'tsx'], 'TypeScript', async () => (await import('@codemirror/legacy-modes/mode/javascript')).typescript);
register(['json', 'jsonc', 'map'], 'JSON', async () => (await import('@codemirror/legacy-modes/mode/javascript')).json);
register(['php'], 'PHP', php);
register(['py'], 'Python', async () => (await import('@codemirror/legacy-modes/mode/python')).python);
register(['rb'], 'Ruby', async () => (await import('@codemirror/legacy-modes/mode/ruby')).ruby);
register(['go'], 'Go', async () => (await import('@codemirror/legacy-modes/mode/go')).go);
register(['rs'], 'Rust', async () => (await import('@codemirror/legacy-modes/mode/rust')).rust);
register(['java'], 'Java', async () => (await import('@codemirror/legacy-modes/mode/clike')).java);
register(['kt', 'kts'], 'Kotlin', async () => (await import('@codemirror/legacy-modes/mode/clike')).kotlin);
register(['cs'], 'C#', async () => (await import('@codemirror/legacy-modes/mode/clike')).csharp);
register(['c', 'h'], 'C', async () => (await import('@codemirror/legacy-modes/mode/clike')).c);
register(['cpp', 'cc', 'hpp'], 'C++', async () => (await import('@codemirror/legacy-modes/mode/clike')).cpp);
register(['swift'], 'Swift', async () => (await import('@codemirror/legacy-modes/mode/swift')).swift);
register(['css'], 'CSS', async () => (await import('@codemirror/legacy-modes/mode/css')).css);
register(['scss'], 'SCSS', async () => (await import('@codemirror/legacy-modes/mode/css')).sCSS);
register(['less'], 'Less', async () => (await import('@codemirror/legacy-modes/mode/css')).less);
register(['html', 'htm', 'vue', 'svelte'], 'HTML', async () => (await import('@codemirror/legacy-modes/mode/xml')).html);
register(['xml', 'svg', 'plist'], 'XML', async () => (await import('@codemirror/legacy-modes/mode/xml')).xml);
register(['sql'], 'SQL', async () => (await import('@codemirror/legacy-modes/mode/sql')).standardSQL);
register(['yml', 'yaml'], 'YAML', async () => (await import('@codemirror/legacy-modes/mode/yaml')).yaml);
register(['toml'], 'TOML', async () => (await import('@codemirror/legacy-modes/mode/toml')).toml);
register(['ini', 'env', 'properties', 'conf', 'cfg'], 'Config', async () => (await import('@codemirror/legacy-modes/mode/properties')).properties);
register(['sh', 'bash', 'zsh'], 'Shell', async () => (await import('@codemirror/legacy-modes/mode/shell')).shell);
register(['diff', 'patch'], 'Diff', async () => (await import('@codemirror/legacy-modes/mode/diff')).diff);
register(['lua'], 'Lua', async () => (await import('@codemirror/legacy-modes/mode/lua')).lua);
register(['pl', 'pm'], 'Perl', async () => (await import('@codemirror/legacy-modes/mode/perl')).perl);
register(['r'], 'R', async () => (await import('@codemirror/legacy-modes/mode/r')).r);
register(['ps1'], 'PowerShell', async () => (await import('@codemirror/legacy-modes/mode/powershell')).powerShell);

const BY_NAME: Record<string, Detected> = {
    dockerfile: { label: 'Dockerfile', parser: async () => (await import('@codemirror/legacy-modes/mode/dockerfile')).dockerFile },
    'nginx.conf': { label: 'nginx', parser: async () => (await import('@codemirror/legacy-modes/mode/nginx')).nginx },
    '.env': BY_EXTENSION.env,
};

/**
 * The language a file's name suggests, as a label and a lazily loaded
 * CodeMirror language. Each mode is its own chunk, fetched only when a
 * file of that type is opened. Null means plain text (logs, CSV, notes).
 */
export function detectLanguage(fileName: string): { label: string; load: () => Promise<StreamLanguage<unknown>> } | null {
    const name = fileName.toLowerCase();
    const extension = name.includes('.') ? name.split('.').pop()! : '';
    const detected = BY_NAME[name] ?? (name.startsWith('dockerfile') ? BY_NAME.dockerfile : BY_EXTENSION[extension]);

    if (!detected) {
        return null;
    }

    return { label: detected.label, load: async () => StreamLanguage.define(await detected.parser()) };
}
