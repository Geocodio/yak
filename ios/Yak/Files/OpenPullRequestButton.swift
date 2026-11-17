import SwiftUI
import YakKit

/// The Open PR button's look: the only rust control on a thread screen. The rust color stays in this file.
struct OpenPullRequestButtonStyle: ButtonStyle {
    @Environment(\.isEnabled) private var isEnabled

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.subheadline.weight(.semibold))
            .foregroundStyle(isEnabled ? Color.yakOnRust : Color.yakMuted)
            .padding(.horizontal, 14).frame(minHeight: 44)
            .background(isEnabled ? Color.yakRust : Color(.systemGray5), in: .capsule)
            .opacity(isEnabled && configuration.isPressed ? 0.85 : 1)
    }
}

/// The Open PR control for one `OpenPullRequestState`.
struct OpenPullRequestButton: View {
    let model: FilesModel
    let showCard: () -> Void

    var body: some View {
        let state = model.openPullRequest
        switch state {
        case .open(let number, _):
            Button(action: showCard) {
                Label("files.open.number \(number)", systemImage: "arrow.triangle.pull")
                    .padding(.horizontal, 14).frame(minHeight: 44)
                    .background(Color(.systemGray5), in: .capsule)
            }
            .font(.subheadline.weight(.semibold)).foregroundStyle(.primary)
            .accessibilityLabel(Text("files.open.number.label \(number)"))
            .accessibilityIdentifier("files.openPullRequest")
        case .held:
            neutral("files.open.held")
        case .sending, .opening:
            Button {
            } label: {
                HStack(spacing: 8) {
                    ProgressView()
                    Text("files.open.opening")
                }
            }
            .buttonStyle(OpenPullRequestButtonStyle()).disabled(true)
            .accessibilityIdentifier("files.openPullRequest")
        case .unavailable(let reason):
            Button {
            } label: {
                Label("files.open.label", systemImage: "arrow.triangle.branch")
            }
            .buttonStyle(OpenPullRequestButtonStyle()).disabled(true)
            .accessibilityHint(Text(Self.hint(reason)))
            .accessibilityIdentifier("files.openPullRequest")
        case .available, .failed:
            Button {
                Task { await model.tapOpenPullRequest() }
            } label: {
                Label("files.open.label", systemImage: "arrow.triangle.branch")
            }
            .buttonStyle(OpenPullRequestButtonStyle())
            .accessibilityIdentifier("files.openPullRequest")
        }
    }

    private func neutral(_ title: LocalizedStringKey) -> some View {
        Text(title).font(.subheadline.weight(.semibold)).padding(.horizontal, 14).frame(minHeight: 44)
            .background(Color(.systemGray5), in: .capsule).opacity(0.6)
            .accessibilityIdentifier("files.openPullRequest")
    }

    static func hint(_ reason: OpenPullRequestUnavailable) -> LocalizedStringResource {
        switch reason {
        case .noChanges: "files.open.hint.noChanges"
        case .readOnly: "files.open.hint.readOnly"
        case .closed: "files.open.hint.closed"
        case .notReady: "files.open.hint.notReady"
        case .loading: "files.open.hint.loading"
        }
    }
}
