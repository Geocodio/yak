import { registerDockPanel } from '@/threads/panels';
import { PreviewPanel } from './PreviewPanel';

registerDockPanel({ id: 'preview', label: 'Preview', order: 10, component: PreviewPanel, visible: () => true });
