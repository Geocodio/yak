import { registerDockPanel } from '@/threads/panels';
import FilesPanel from './FilesPanel';

registerDockPanel({ id: 'files', label: 'Files', order: 30, component: FilesPanel, visible: () => true });
