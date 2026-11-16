import { registerDockPanel } from '@/threads/panels';
import { TerminalPanel } from './TerminalPanel';

registerDockPanel({
    id: 'terminal',
    label: 'Terminal',
    order: 40,
    component: TerminalPanel,
    visible: (thread) => thread.status === 'open',
});
