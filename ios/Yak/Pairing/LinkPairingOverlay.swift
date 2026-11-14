import SwiftUI
import YakKit

extension View {
    /// Covers the screen while a scanned or opened pairing link is being paired, and shows why it failed.
    func linkPairingStatus(_ model: AppModel) -> some View {
        overlay { LinkPairingOverlay(model: model) }
    }
}

private struct LinkPairingOverlay: View {
    let model: AppModel
    @State private var showsChecking = false

    var body: some View {
        if let pairingModel = model.linkPairing {
            content(pairingModel.state)
                .task(id: pairingModel.state) {
                    showsChecking = false
                    guard pairingModel.state == .checking else { return }
                    // Nothing for the first 300 ms, then the progress state.
                    do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
                    showsChecking = true
                }
                .sensoryFeedback(.error, trigger: pairingModel.state) { _, new in
                    if case .problem = new { return true }
                    return false
                }
        }
    }

    /// The code in a link works once and for two minutes, so sending it again cannot succeed.
    private func oneTimeCodeIsSpent(_ problem: PairingProblem) -> Bool {
        problem == .codeExpired || problem == .codeInvalid
    }

    @ViewBuilder
    private func content(_ state: PairingModel.State) -> some View {
        switch state {
        case .problem(let problem):
            VStack(spacing: 16) {
                PairingProblemView(
                    problem: problem, address: model.linkHost ?? "",
                    retry: oneTimeCodeIsSpent(problem) ? nil : { Task { await model.retryLinkPairing() } })
                Button("common.close") { model.dismissLinkPairing() }.buttonStyle(SecondaryButtonStyle())
            }
            .frame(maxWidth: 560)
            .padding(24)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color(.systemBackground).ignoresSafeArea())
            .accessibilityAddTraits(.isModal)
            .transition(.opacity)
        case .checking where showsChecking:
            VStack(spacing: 16) {
                ProgressView()
                Text("pairLink.checking").font(.body).foregroundStyle(.secondary)
                SlowHint { Task { await model.retryLinkPairing() } }
                Button("common.close") { model.dismissLinkPairing() }.buttonStyle(SecondaryButtonStyle())
            }
            .padding(24)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color(.systemBackground).ignoresSafeArea())
            .accessibilityAddTraits(.isModal)
            .transition(.opacity)
        default:
            EmptyView()
        }
    }
}
