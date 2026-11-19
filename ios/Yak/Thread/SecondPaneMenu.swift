import SwiftUI
import YakKit

/// The "Second pane" section of a pane menu: "None" and one item per pane, the current choice checked. Shown only
/// where the size has room for two panes.
struct SecondPaneMenuSection: View {
    @Environment(SceneModel.self) private var scene

    var body: some View {
        if let choices = scene.secondChoices, choices.isOffered {
            Section {
                Button {
                    scene.setSecond(nil)
                } label: {
                    choiceLabel(Text("pane.second.none"), isCurrent: choices.current == nil)
                }
                .accessibilityIdentifier("pane.second.none")
                ForEach(choices.panes, id: \.self) { id in
                    if let descriptor = PaneCatalog.descriptor(for: id) {
                        Button {
                            scene.setSecond(id)
                        } label: {
                            choiceLabel(Text(descriptor.name), isCurrent: id == choices.current)
                        }
                        .accessibilityIdentifier("pane.second.\(id.rawValue)")
                    }
                }
            } header: {
                Text("pane.second.heading")
            }
        }
    }
}

@ViewBuilder private func choiceLabel(_ title: Text, isCurrent: Bool) -> some View {
    if isCurrent {
        Label {
            title
        } icon: {
            Image(systemName: "checkmark")
        }
    } else {
        title
    }
}

/// The same choice on a pane item that has no menu button beside it (a row, a rail, the system's strip): a long press
/// or a secondary click opens or closes the second pane.
private struct SecondPaneContextMenu: ViewModifier {
    let pane: PaneID
    @Environment(SceneModel.self) private var scene

    /// The menu has an item only at a size with room for two panes; elsewhere it is not attached at all, so a
    /// long press does not lift the item for an empty menu.
    private var hasItem: Bool {
        guard let choices = scene.secondChoices, choices.isOffered else { return false }
        return choices.current == pane || choices.panes.contains(pane)
    }

    @ViewBuilder func body(content: Content) -> some View {
        if hasItem {
            content.contextMenu {
                if scene.secondChoices?.current == pane {
                    Button {
                        scene.setSecond(nil)
                    } label: {
                        Label("pane.second.close", systemImage: "rectangle.split.1x2.slash")
                    }
                    .accessibilityIdentifier("pane.second.close")
                } else {
                    Button {
                        scene.setSecond(pane)
                    } label: {
                        Label("pane.second.open", systemImage: "rectangle.split.1x2")
                    }
                    .accessibilityIdentifier("pane.second.open")
                }
            }
        } else {
            content
        }
    }
}

extension View {
    func secondPaneContextMenu(for pane: PaneID) -> some View {
        modifier(SecondPaneContextMenu(pane: pane))
    }
}
