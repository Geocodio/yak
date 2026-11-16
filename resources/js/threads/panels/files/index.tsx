import { registerDockPanel } from '@/threads/panels';
import { ChangesBadge } from '../changes/ChangesBadge';
import FilesView from './FilesView';

registerDockPanel({ id: 'files', label: 'Files', order: 20, component: FilesView, visible: () => true, badge: ChangesBadge });
