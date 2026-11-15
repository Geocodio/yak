import SwiftUI
import YakKit

/// Everything the thread screen knows about one pane: its name, its icon, its content and its row in the bottom
/// bar. The frame reads panes only through this type, so no other file names a specific pane.
struct PaneDescriptor {
    let id: PaneID
    let name: LocalizedStringResource
    let symbol: String
    /// The pane's content, filling the area above the bottom bar.
    let content: @MainActor (ThreadModel) -> AnyView
    /// What the bottom bar shows beside the pane control while this pane is focused.
    let barRow: @MainActor (ThreadModel) -> AnyView
}

/// The one list of the panes this build draws. A new pane is one view file of its own, a `PaneDescriptor` in it,
/// one line here and its id in `Panes.built`.
@MainActor
enum PaneCatalog {
    static let all: [PaneDescriptor] = [
        .chat
    ]

    static func descriptor(for id: PaneID) -> PaneDescriptor? { all.first { $0.id == id } }
}
