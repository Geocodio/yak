import SwiftUI
import UIKit
import YakKit

/// What the menu commands do in the focused window. `InstanceShell` publishes it with `focusedSceneValue`.
struct SceneCommands {
    /// The panes the open thread has; the others are disabled, never renumbered.
    let availablePanes: [PaneID]
    let focusPane: (PaneID) -> Void
    let searchThreads: () -> Void
    let newThread: () -> Void
    let toggleSidebar: () -> Void
    let overview: () -> Void
    let settings: () -> Void
}

extension FocusedValues {
    @Entry var sceneCommands: SceneCommands?
}

/// The scene-level shortcuts as menu commands, so they work in the key window and show in the system's shortcut
/// overlay grouped by menu.
struct YakCommands: Commands {
    @FocusedValue(\.sceneCommands) private var scene
    @Environment(\.openWindow) private var openWindow

    var body: some Commands {
        CommandGroup(after: .newItem) {
            Button(AppShortcut.newThread.title) { scene?.newThread() }.yakShortcut(.newThread).disabled(scene == nil)
            Button(AppShortcut.newWindow.title) {
                WindowRoutes.newWindow(
                    open: { openWindow(value: $0) },
                    supportsMultipleWindows: UIApplication.shared.supportsMultipleScenes)
            }
            .yakShortcut(.newWindow)
        }
        CommandGroup(after: .sidebar) {
            Button(AppShortcut.toggleSidebar.title) { scene?.toggleSidebar() }.yakShortcut(.toggleSidebar)
                .disabled(scene == nil)
        }
        CommandGroup(after: .textEditing) {
            Button(AppShortcut.searchThreads.title) { scene?.searchThreads() }.yakShortcut(.searchThreads)
                .disabled(scene == nil)
        }
        CommandMenu(Text("menu.thread")) {
            ForEach([AppShortcut.focusChat, .focusPreview, .focusFiles, .focusTerminal, .focusOverview], id: \.self) {
                shortcut in
                if let pane = shortcut.pane {
                    Button(shortcut.title) { scene?.focusPane(pane) }.yakShortcut(shortcut)
                        .disabled(!(scene?.availablePanes.contains(pane) ?? false))
                }
            }
            Divider()
            Button(AppShortcut.overview.title) { scene?.overview() }.yakShortcut(.overview)
                .disabled(!(scene?.availablePanes.contains(.overview) ?? false))
        }
        CommandGroup(replacing: .appSettings) {
            Button(AppShortcut.settings.title) { scene?.settings() }.yakShortcut(.settings).disabled(scene == nil)
        }
    }
}
