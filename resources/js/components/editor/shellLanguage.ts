import { StreamLanguage } from '@codemirror/language';
import { shell } from '@codemirror/legacy-modes/mode/shell';

/** Bash/shell syntax highlighting for command fields (cold start, checkout refresh). */
export function shellLanguage() {
    return StreamLanguage.define(shell);
}
