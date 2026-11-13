// One import line per panel file. A track adds its view by creating a file in
// this folder that calls registerDockPanel (or registerOverviewSection for a
// group of the Overview rail), and importing it here.
export {};
import './environment';
import './stack';
import './preview';
import './changes';
import '@/threads/panels/terminal';
import './files';
