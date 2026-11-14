// One import line per panel file. A track adds its view by creating a file in
// this folder that calls registerDockPanel (or registerOverviewSection for a
// group of the Overview rail), and importing it here.
export {};
import './environment';
import './context';
import './stack';
import './preview';
import '@/threads/panels/terminal';
import './files';
import './shared-files';
