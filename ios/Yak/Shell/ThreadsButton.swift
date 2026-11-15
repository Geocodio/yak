import SwiftUI

/// The toolbar button that opens the threads drawer, with the count of threads that need the person.
struct ThreadsButton: View {
    let needsYouCount: Int
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: "sidebar.leading")
                .frame(minWidth: 44, minHeight: 44)
                .overlay(alignment: .topTrailing) {
                    if needsYouCount > 0 {
                        // The system strip of a folding phone drops a button with a text badge, so the badge is a plain dot.
                        // The label carries the count.
                        Circle().fill(Color.red).frame(width: 10, height: 10).padding(6).accessibilityHidden(true)
                    }
                }
                .contentShape(.rect)
        }
        .accessibilityLabel(
            needsYouCount > 0 ? Text("threads.button.needsYou \(needsYouCount)") : Text("threads.button")
        )
        .accessibilityIdentifier("threads.button")
    }
}
