import fs from 'node:fs';

const CLOCK_TICKS_PER_SECOND = 100;

// Reads /proc. Any unreadable pid is skipped, so this never throws.
export function listProcesses(procRoot = '/proc') {
  let bootTimeMs = 0;
  try {
    const match = /^btime (\d+)$/m.exec(fs.readFileSync(`${procRoot}/stat`, 'utf8'));
    bootTimeMs = match ? Number(match[1]) * 1000 : 0;
  } catch { return []; }
  let names = [];
  try { names = fs.readdirSync(procRoot).filter((name) => /^\d+$/.test(name)); } catch { return []; }
  const processes = [];
  for (const name of names) {
    try {
      const stat = fs.readFileSync(`${procRoot}/${name}/stat`, 'utf8');
      // The command name sits in parentheses and may contain spaces, so split after the last ')'.
      const fields = stat.slice(stat.lastIndexOf(')') + 2).split(' ');
      const uid = Number(/^Uid:\s+(\d+)/m.exec(fs.readFileSync(`${procRoot}/${name}/status`, 'utf8'))?.[1]);
      let command = '';
      try { command = fs.readFileSync(`${procRoot}/${name}/cmdline`, 'utf8').split('\0').join(' ').trim(); } catch { /* kernel thread or gone */ }
      processes.push({
        pid: Number(name),
        ppid: Number(fields[1]),
        uid,
        startedAtMs: bootTimeMs + (Number(fields[19]) / CLOCK_TICKS_PER_SECOND) * 1000,
        command,
      });
    } catch { /* the process exited while being read */ }
  }
  return processes;
}

// Pids owned by agentUid that started at or after turnStartedAtMs and are neither protected,
// nor a descendant or an ancestor of a protected pid. Pid 1 is never selected.
export function selectLeftovers(processes, { agentUid, turnStartedAtMs, protectedPids }) {
  const parentOf = new Map(processes.map((entry) => [entry.pid, entry.ppid]));
  const isDescendantOfProtected = (pid) => {
    for (let current = parentOf.get(pid), hops = 0; current && hops < 1000; current = parentOf.get(current), hops += 1) {
      if (protectedPids.has(current)) return true;
    }
    return false;
  };
  const ancestors = new Set();
  for (const pid of protectedPids) {
    for (let current = parentOf.get(pid), hops = 0; current && hops < 1000; current = parentOf.get(current), hops += 1) ancestors.add(current);
  }
  return processes
    .filter((entry) => entry.pid !== 1
      && entry.uid === agentUid
      && entry.startedAtMs >= turnStartedAtMs
      && !protectedPids.has(entry.pid)
      && !ancestors.has(entry.pid)
      && !isDescendantOfProtected(entry.pid))
    .map((entry) => entry.pid);
}
