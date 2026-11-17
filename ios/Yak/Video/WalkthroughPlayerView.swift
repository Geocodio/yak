import SwiftUI
import UIKit
import YakKit

/// The playback's one `AVPlayerViewController`. Mounting it first detaches it from any earlier parent, because a
/// controller can only have one.
struct WalkthroughPlayerView: UIViewControllerRepresentable {
    let playback: WalkthroughPlayback
    let scene: SceneModel

    func makeUIViewController(context: Context) -> UIViewController {
        playback.prepareControllerForMount(in: scene)
        return playback.controller
    }

    func updateUIViewController(_ controller: UIViewController, context: Context) {}
}

/// The full player: the system's controls on black, with what the phase says over the video while it is not playing.
struct WalkthroughPlayerSheet: View {
    let playback: WalkthroughPlayback
    let scene: SceneModel
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        ZStack {
            Color.black
            WalkthroughPlayerView(playback: playback, scene: scene)
                .accessibilityIdentifier("walkthrough.player")
            overlay
        }
        .ignoresSafeArea()
        .overlay(alignment: .topLeading) {
            Button("common.close") { playback.close() }
                .buttonStyle(.borderedProminent).tint(.black.opacity(0.6)).foregroundStyle(.white)
                .padding(16).accessibilityIdentifier("walkthrough.close")
        }
        .presentationBackground(.black)
        .presentationDetents([.large])
        .presentationDragIndicator(.hidden)
        .preferredColorScheme(.dark)
    }

    @ViewBuilder private var overlay: some View {
        switch playback.phase {
        case .loading, .slow:
            VStack(spacing: 16) {
                if let poster = playback.current?.posterURL {
                    AsyncImage(url: poster) {
                        $0.resizable().scaledToFit()
                    } placeholder: {
                        Color.clear
                    }
                    .accessibilityHidden(true)
                }
                ProgressView().controlSize(.large).tint(.white).accessibilityIdentifier("walkthrough.loading")
                if playback.phase == .slow {
                    Text("walkthrough.slow").font(.body).foregroundStyle(.white).multilineTextAlignment(.center)
                        .accessibilityIdentifier("walkthrough.slow")
                    Button("common.retry") { Task { await playback.retry() } }
                        .buttonStyle(.bordered).controlSize(.large).tint(.white)
                        .frame(minHeight: 44).accessibilityIdentifier("walkthrough.retry")
                }
            }
            .padding(24)
        case .failed(let problem):
            failure(problem).padding(24)
        case .playing, nil:
            EmptyView()
        }
    }

    private func failure(_ problem: WalkthroughVideoProblem) -> some View {
        VStack(spacing: 12) {
            Image(systemName: "exclamationmark.triangle.fill").font(.title).foregroundStyle(.white)
                .frame(width: 56, height: 56).background(Color.white.opacity(0.15), in: .rect(cornerRadius: 16))
                .accessibilityHidden(true)
            Text("walkthrough.failed.title").font(.headline).foregroundStyle(.white)
            Text(Self.sentence(problem)).font(.body).foregroundStyle(.white)
            if Self.offersRetry(problem) {
                Button("walkthrough.tryAgain") { Task { await playback.retry() } }
                    .buttonStyle(.bordered).controlSize(.large).tint(.white)
                    .frame(minHeight: 44).accessibilityIdentifier("walkthrough.tryAgain")
            }
        }
        .multilineTextAlignment(.center)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("walkthrough.failed.\(Self.name(problem))")
    }

    private static func sentence(_ problem: WalkthroughVideoProblem) -> LocalizedStringKey {
        switch problem {
        case .offline: "walkthrough.offline"
        case .expired: "walkthrough.expired"
        case .timedOut: "walkthrough.timedOut"
        case .unsupported: "walkthrough.unsupported"
        case .other: "walkthrough.other"
        }
    }

    private static func name(_ problem: WalkthroughVideoProblem) -> String {
        switch problem {
        case .offline: "offline"
        case .expired: "expired"
        case .timedOut: "timedOut"
        case .unsupported: "unsupported"
        case .other: "other"
        }
    }

    /// Offline retries by itself when the network returns; an unsupported format cannot be fixed by asking again.
    private static func offersRetry(_ problem: WalkthroughVideoProblem) -> Bool {
        switch problem {
        case .offline, .unsupported: false
        case .expired, .timedOut, .other: true
        }
    }
}
