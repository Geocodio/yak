// One import line per panel file. A track adds its panel by creating a file in
// this folder that calls registerSidebarPanel or registerDockPanel, and
// importing it here.
export {};
import './environment';
import './changes';
import '@/threads/panels/teleport';
