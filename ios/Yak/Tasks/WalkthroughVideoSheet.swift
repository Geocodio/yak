import AVKit
import SwiftUI

/// The task's walkthrough video in a sheet.
struct WalkthroughVideoSheet: View {
    let url: URL
    let close: () -> Void
    @State private var player: AVPlayer?

    var body: some View {
        NavigationStack {
            Group {
                if let player { VideoPlayer(player: player) } else { ProgressView() }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color(.systemBackground))
            .navigationTitle("task.walkthrough.title")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("common.close", action: close).accessibilityIdentifier("task.video.close")
                }
            }
        }
        .task { player = AVPlayer(url: url) }
        .onDisappear { player?.pause() }
    }
}
