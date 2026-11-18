import SwiftUI

/// The detail of a window that shows no thread.
struct NoThreadView: View {
    let startThread: () -> Void

    var body: some View {
        VStack(spacing: 12) {
            Image(systemName: "rectangle.split.1x2").font(.largeTitle).foregroundStyle(.secondary)
                .accessibilityHidden(true)
            VStack(spacing: 4) {
                Text("window.noThread.title").font(.title3.weight(.semibold))
                Text("window.noThread.body").font(.body).foregroundStyle(.secondary)
            }
            .multilineTextAlignment(.center)
            .accessibilityElement(children: .combine)
            Button(action: startThread) {
                Text("window.noThread.start").font(.body.weight(.medium)).padding(.horizontal, 16)
                    .frame(minHeight: 44)
                    .background(Color.primary.opacity(0.08), in: .capsule)
            }
            .buttonStyle(.plain)
            .accessibilityIdentifier("window.noThread.start")
        }
        .padding(24)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemBackground))
        .accessibilityIdentifier("window.noThread")
    }
}
