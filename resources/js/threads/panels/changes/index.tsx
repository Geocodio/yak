import { registerDockPanel } from '@/threads/panels';
import { ChangesBadge } from './ChangesBadge';
import { ChangesPanel } from './ChangesPanel';

registerDockPanel({ id: 'changes', label: 'Changes', order: 20, component: ChangesPanel, visible: () => true, badge: ChangesBadge });
