import SwiftUI
import YakKit

/// The thread screen until the real one arrives: the title, and the button that opens the drawer.
struct ThreadPlaceholder: View {
    let title: String?
    let isNewThread: Bool
    let showsThreadsButton: Bool
    let openDrawer: () -> Void

    var body: some View {
        NavigationStack {
            VStack(spacing: 16) {
                if isNewThread {
                    Text("shell.newThreadTitle").font(.title2.bold())
                } else if let title {
                    Text(title).font(.title2.bold()).multilineTextAlignment(.center)
                } else {
                    Text("shell.noThread").foregroundStyle(Color.yakMuted).multilineTextAlignment(.center)
                }
            }
            .padding(24)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .accessibilityElement(children: .contain)
            .accessibilityIdentifier("conversation")
            .background(Color(.systemBackground))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if showsThreadsButton {
                    ToolbarItem(placement: .topBarLeading) {
                        Button(action: openDrawer) { Image(systemName: "line.3.horizontal") }
                            .accessibilityLabel("shell.threads")
                            .accessibilityIdentifier("threads.button")
                    }
                }
            }
        }
    }
}
