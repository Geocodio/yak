import { registerDockPanel } from '@/threads/panels';
import { AgentsBadge } from './AgentsBadge';
import { AgentsPanel } from './AgentsPanel';

registerDockPanel({ id: 'agents', label: 'Agents', order: 50, component: AgentsPanel, visible: () => true, badge: AgentsBadge });
