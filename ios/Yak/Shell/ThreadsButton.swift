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
                        Text(needsYouCount, format: .number).font(.caption2.weight(.bold)).foregroundStyle(.white)
                            .padding(.horizontal, 5).frame(minWidth: 16, minHeight: 16)
                            .background(Color.red, in: .capsule).offset(x: -2, y: 4)
                            .accessibilityHidden(true)
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
