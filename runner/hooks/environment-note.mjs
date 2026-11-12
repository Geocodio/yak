/**
 * SessionStart command hook. On a resumed session it prints the environment note once as
 * additionalContext and deletes the file: every resume copies the note into the transcript,
 * so a note that stayed on disk would be repeated on each later resume. A hook failure must
 * never block the session, so every error path exits 0 silently.
 */
import { readFileSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';

try {
  const { source } = JSON.parse(readFileSync(0, 'utf8'));
  if (source === 'resume') {
    const notePath = join(process.env.YAK_ENVIRONMENT_NOTE_DIRECTORY || '/home/yak/.yak', 'environment-note.md');
    const note = readFileSync(notePath, 'utf8');
    if (note.trim() !== '') {
      process.stdout.write(JSON.stringify({ hookSpecificOutput: { hookEventName: 'SessionStart', additionalContext: note } }));
      unlinkSync(notePath);
    }
  }
} catch {
  // Missing note, bad stdin or unlink failure: stay silent.
}
