import { registerSidebarPanel } from '@/threads/panels';
import { ChangesPanel } from './ChangesPanel';

registerSidebarPanel({ id: 'changes', label: 'Changes', order: 20, component: ChangesPanel, visible: () => true });
