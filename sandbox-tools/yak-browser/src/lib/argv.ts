/** Read the value of `flag` from argv, supporting `--flag value` and `--flag=value`. */
export function getFlag(argv: string[], flag: string): string | undefined {
  const i = argv.indexOf(flag);
  if (i >= 0 && i < argv.length - 1) return argv[i + 1];
  for (const a of argv) {
    if (a.startsWith(flag + '=')) return a.slice(flag.length + 1);
  }
  return undefined;
}

/**
 * Find the first positional (non-flag) token in argv, skipping any flag and
 * the value that belongs to it — so flag-first ordering (e.g.
 * `script --base <url> file.json`) doesn't misread a flag's value (like a
 * URL) as the positional argument. `valueFlags` lists the flags that consume
 * the next token as their value; every other `--flag` is treated as boolean.
 */
export function pickPositional(argv: string[], valueFlags: readonly string[]): string | undefined {
  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    if (!arg.startsWith('--')) return arg;
    if (arg.includes('=')) continue;
    if (valueFlags.includes(arg)) i += 1;
  }
  return undefined;
}
