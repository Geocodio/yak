import SwiftUI
import YakKit

/// "Open in new window", shown only where the system supports several windows.
struct OpenInNewWindowButton: View {
    let threadID: String
    let instanceID: UUID

    @Environment(\.supportsMultipleWindows) private var supportsMultipleWindows
    @Environment(\.openWindow) private var openWindow

    var body: some View {
        if supportsMultipleWindows {
            Button {
                WindowRoutes.open(
                    thread: threadID, instance: instanceID, open: { openWindow(value: $0) },
                    supportsMultipleWindows: supportsMultipleWindows)
            } label: {
                Label("row.openInNewWindow", systemImage: "macwindow.badge.plus")
            }
            .accessibilityIdentifier("row.openInNewWindow")
        }
    }
}
