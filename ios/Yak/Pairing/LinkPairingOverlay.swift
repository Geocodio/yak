import SwiftUI
import YakKit

extension View {
    /// Covers the screen while a scanned or opened pairing link is being paired, and shows why it failed.
    func linkPairingStatus(_ model: AppModel) -> some View {
        // What the overlay covers cannot be tapped while a pairing shows. Screens hide their own content from
        // VoiceOver with `hidesBehindLinkPairing`, because a navigation stack ignores a modifier set outside it.
        allowsHitTesting(model.linkPairing == nil)
            .overlay { LinkPairingOverlay(model: model) }
    }
}

extension View {
    /// Takes this screen out of the accessibility tree while the pairing overlay covers it.
    func hidesBehindLinkPairing(_ model: AppModel) -> some View {
        accessibilityHidden(model.linkPairing != nil)
    }
}

private struct LinkPairingOverlay: View {
    let model: AppModel
    @State private var showsChecking = false

    var body: some View {
        // The container stays when there is nothing to show, so the timer below keeps running.
        ZStack {
            if let pairingModel = model.linkPairing { content(pairingModel.state) }
        }
        .task(id: model.linkPairing?.state) {
            showsChecking = false
            guard model.linkPairing?.state == .checking else { return }
            // Nothing for the first 300 ms, then the progress state.
            do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
            showsChecking = true
        }
        .sensoryFeedback(.error, trigger: model.linkPairing?.state) { _, new in
            if case .problem = new { return true }
            return false
        }
    }

    /// A full-screen page that scrolls when the text is larger than the screen.
    private func page<Content: View>(@ViewBuilder _ content: () -> Content) -> some View {
        ScrollView {
            content()
                .frame(maxWidth: 560, alignment: .leading)
                .padding(24)
                .padding(.top, 40)
                .frame(maxWidth: .infinity)
        }
        .scrollBounceBehavior(.basedOnSize)
        .background(Color(.systemBackground).ignoresSafeArea())
        .accessibilityAddTraits(.isModal)
        .transition(.opacity)
    }

    /// The code in a link works once and for two minutes, so sending it again cannot succeed.
    private func oneTimeCodeIsSpent(_ problem: PairingProblem) -> Bool {
        problem == .codeExpired || problem == .codeInvalid || problem == .codeMayBeUsed
    }

    @ViewBuilder
    private func content(_ state: PairingModel.State) -> some View {
        switch state {
        case .problem(let problem):
            page {
                VStack(spacing: 16) {
                    PairingProblemView(
                        problem: problem, address: model.linkHost ?? "",
                        retry: oneTimeCodeIsSpent(problem) ? nil : { Task { await model.retryLinkPairing() } })
                    if problem == .codeMayBeUsed {
                        Button("pairLink.scanAgain") { model.scanAgain() }.buttonStyle(PrimaryButtonStyle())
                    } else {
                        Button("common.close") { model.dismissLinkPairing() }.buttonStyle(SecondaryButtonStyle())
                    }
                }
            }
        case .confirming(let link, let instance, let replaces):
            confirmation(link: link, instance: instance, replaces: replaces, isRedeeming: false)
        case .redeeming(let link, let instance, let replaces):
            confirmation(link: link, instance: instance, replaces: replaces, isRedeeming: true)
        case .checking where showsChecking:
            page {
                VStack(alignment: .leading, spacing: 16) {
                    HStack(spacing: 10) {
                        ProgressView().accessibilityHidden(true)
                        Text("pairLink.checking").font(.body).foregroundStyle(Color.yakMuted)
                    }
                    SlowHint { Task { await model.retryLinkPairing() } }
                    Button("common.close") { model.dismissLinkPairing() }.buttonStyle(SecondaryButtonStyle())
                }
            }
        default:
            EmptyView()
        }
    }

    /// The confirmation, which stays as it is while the code is redeemed: "Pair" shows its progress
    /// and ignores taps, "Cancel" is disabled, and a slow connection offers to stop
    /// waiting. Nothing behind it shows through or can be tapped.
    private func confirmation(link: PairingLink, instance: InstanceInfo, replaces: Instance?, isRedeeming: Bool)
        -> some View
    {
        let host = link.instance.host() ?? link.instance.absoluteString
        return page {
            VStack(alignment: .leading, spacing: 16) {
                Image(systemName: "link")
                    .font(.system(size: 40, weight: .light))
                    .foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true)
                Text("pairLink.confirm.title").font(.title2.bold())
                VStack(alignment: .leading, spacing: 4) {
                    Text(instance.name).font(.headline)
                    Text(host).font(.subheadline.monospaced()).foregroundStyle(Color.yakMuted)
                }
                .padding(12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(.quaternary, in: .rect(cornerRadius: 10))
                .accessibilityElement(children: .combine)
                Text("pairLink.confirm.warning").font(.body)
                if replaces != nil {
                    Text("pairLink.confirm.replaces").font(.body).foregroundStyle(Color.yakMuted)
                }
                Button {
                    Task { await model.confirmLinkPairing() }
                } label: {
                    if isRedeeming {
                        HStack(spacing: 10) {
                            ProgressView().tint(Color.yakOnRust).accessibilityHidden(true)
                            Text("pairLink.confirm.pairing")
                        }
                    } else {
                        Text("pairLink.confirm.pair")
                    }
                }
                .buttonStyle(PrimaryButtonStyle())
                .accessibilityLabel(
                    Text(isRedeeming ? "pairLink.confirm.pairing" : "pairLink.confirm.pairLabel \(host)"))
                Button("pairLink.confirm.cancel") { model.dismissLinkPairing() }
                    .buttonStyle(SecondaryButtonStyle())
                    .disabled(isRedeeming)
                    .accessibilityLabel(Text("pairLink.confirm.cancelLabel"))
                if isRedeeming {
                    SlowHint(actionTitle: "pairLink.giveUp") { model.abandonLinkRedeem() }
                }
            }
        }
    }
}
