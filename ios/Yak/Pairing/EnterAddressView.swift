import AuthenticationServices
import SwiftUI
import UIKit
import YakKit

/// The typed-address route: check the address, then sign in with Google on the instance and
/// approve this device.
struct EnterAddressView: View {
    let model: AppModel
    var initialAddress = ""
    var onDone: (() -> Void)?

    @State private var pairingModel: PairingModel?
    @State private var address = ""
    @State private var sessionTask: Task<Void, Never>?
    @State private var showsChecking = false
    @FocusState private var isFocused: Bool
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.webAuthenticationSession) private var webAuthenticationSession

    var body: some View {
        Group {
            if let pairingModel {
                content(pairingModel)
            } else {
                Color.clear
            }
        }
        .navigationBarTitleDisplayMode(.inline)
        .onAppear {
            if pairingModel == nil {
                pairingModel = model.makePairingModel()
                address = initialAddress
            }
            isFocused = true
        }
        .onDisappear {
            sessionTask?.cancel()
            pairingModel?.cancel()
        }
    }

    private func content(_ pairingModel: PairingModel) -> some View {
        ScrollViewReader { scroller in
            ScrollView {
                VStack(alignment: .leading, spacing: 20) {
                    Text("address.title").font(.yak(.largeTitle, .bold))
                    switch pairingModel.state {
                    case .problem(let problem):
                        PairingProblemView(problem: problem, address: address, isTypedAddress: true) {
                            pairingModel.reset()
                        }
                    case .waitingForApproval:
                        waiting(pairingModel)
                    case .paired:
                        ProgressView()
                    default:
                        form(pairingModel)
                    }
                }
                .frame(maxWidth: 560, alignment: .leading)
                .frame(maxWidth: .infinity)
                .padding(24)
            }
            .scrollDismissesKeyboard(.interactively)
            .onChange(of: pairingModel.confirmation != nil) { _, isFound in
                // The keyboard may cover the button that the found instance makes useful.
                if isFound { withAnimation { scroller.scrollTo("continue", anchor: .bottom) } }
            }
            .onChange(of: pairingModel.state) { _, newState in handle(newState, pairingModel) }
            .onChange(of: model.network.isOnline) { _, isOnline in
                guard isOnline else { return }
                // The connection is back: run again what the lost connection stopped.
                if pairingModel.state == .problem(.unreachable) {
                    pairingModel.reset()
                    start(pairingModel)
                } else if pairingModel.checkProblem == .unreachable {
                    Task { await pairingModel.check(address: address) }
                }
            }
            .task(id: pairingModel.state == .checking) {
                // Nothing for the first 300 ms, then the progress state.
                guard pairingModel.state == .checking else {
                    showsChecking = false
                    return
                }
                do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
                showsChecking = true
            }
            .sensoryFeedback(.success, trigger: isPaired(pairingModel.state))
            .sensoryFeedback(.error, trigger: isProblem(pairingModel.state))
        }
    }

    private func form(_ pairingModel: PairingModel) -> some View {
        let isChecking = pairingModel.state == .checking
        return VStack(alignment: .leading, spacing: 20) {
            // The prefix goes above the field at accessibility sizes, where both would not fit on one line.
            let layout =
                dynamicTypeSize.isAccessibilitySize
                ? AnyLayout(VStackLayout(alignment: .leading)) : AnyLayout(HStackLayout(spacing: 0))
            layout {
                Text("address.prefix").foregroundStyle(Color.yakMuted)
                TextField("address.placeholder", text: $address)
                    .textContentType(.URL)
                    .keyboardType(.URL)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .submitLabel(.go)
                    .focused($isFocused)
                    .disabled(isChecking)
                    .onSubmit { start(pairingModel) }
            }
            .font(.yak(.body))
            .padding(14)
            .frame(minHeight: 44)
            .background(.quaternary, in: .rect(cornerRadius: 12))
            .task(id: address) {
                do { try await Task.sleep(for: .milliseconds(600)) } catch { return }
                await pairingModel.check(address: address)
            }
            if !model.network.isOnline {
                Label("common.offline", systemImage: "wifi.slash")
                    .font(.yak(.subheadline)).foregroundStyle(Color.yakMuted)
            } else if let problem = pairingModel.checkProblem, !isChecking {
                Text(LocalizedStringKey(problem.titleKey)).font(.yak(.subheadline)).foregroundStyle(Color.yakMuted)
            }
            if let info = pairingModel.confirmation, !isChecking {
                HStack(spacing: 12) {
                    Image(systemName: "checkmark.circle.fill").foregroundStyle(.green).accessibilityHidden(true)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(info.name).font(.yak(.headline))
                        Text("address.found").font(.yak(.subheadline)).foregroundStyle(Color.yakMuted)
                    }
                }
                .accessibilityElement(children: .combine)
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
            Button {
                start(pairingModel)
            } label: {
                if isChecking && showsChecking {
                    HStack(spacing: 10) {
                        ProgressView().tint(Color.yakOnRust).accessibilityHidden(true)
                        Text("address.checking")
                    }
                } else {
                    Text("address.continue")
                }
            }
            .buttonStyle(PrimaryButtonStyle())
            .id("continue")
            if isChecking {
                SlowHint { retry(pairingModel) }
            }
            Text("address.footnote").font(.yak(.footnote)).foregroundStyle(Color.yakMuted)
        }
        .animation(.spring, value: pairingModel.confirmation != nil)
        .animation(.spring, value: pairingModel.checkProblem)
    }

    private func waiting(_ pairingModel: PairingModel) -> some View {
        VStack(alignment: .leading, spacing: 16) {
            HStack(spacing: 10) {
                ProgressView().accessibilityHidden(true)
                Text("address.waiting")
            }
            Text("address.waitingDetail").font(.yak(.body)).foregroundStyle(Color.yakMuted)
            #if DEBUG
                // The nightly journeys approve through the web with this address; test mode only.
                if TestMode.current() != nil, case .waitingForApproval(let url) = pairingModel.state {
                    Text(verbatim: url.absoluteString).font(.yak(.caption2)).foregroundStyle(Color.yakMuted)
                        .accessibilityIdentifier("pairing.approvalURL")
                }
            #endif
            Button("address.cancel") { pairingModel.cancel() }.buttonStyle(SecondaryButtonStyle())
        }
    }

    private func retry(_ pairingModel: PairingModel) {
        pairingModel.cancel()
        start(pairingModel)
    }

    private func start(_ pairingModel: PairingModel) {
        guard pairingModel.state == .idle else { return }
        isFocused = false
        Task { await pairingModel.submit(address: address) }
    }

    private func handle(_ state: PairingModel.State, _ pairingModel: PairingModel) {
        switch state {
        case .waitingForApproval(let url):
            #if DEBUG
                if model.skipsWebSession { return }
            #endif
            sessionTask?.cancel()
            sessionTask = Task {
                do {
                    _ = try await webAuthenticationSession.authenticate(
                        using: url, callbackURLScheme: "yak", preferredBrowserSession: .shared)
                } catch {
                    // A pairing that already succeeded cancelled this task, which must not undo it.
                    guard !Task.isCancelled else { return }
                    if let sessionError = error as? ASWebAuthenticationSessionError,
                        sessionError.code == .canceledLogin
                    {
                        pairingModel.cancel()
                    } else {
                        pairingModel.report(.other("web_session_failed"))
                    }
                }
            }
        case .paired:
            sessionTask?.cancel()
            onDone?()
        case .problem:
            // A problem found while the sign-in sheet is open closes it, and closing it does not undo the problem.
            sessionTask?.cancel()
        default:
            break
        }
    }

    private func isPaired(_ state: PairingModel.State) -> Bool {
        if case .paired = state { return true }
        return false
    }

    private func isProblem(_ state: PairingModel.State) -> Bool {
        if case .problem = state { return true }
        return false
    }
}

/// After 8 seconds of waiting, says the connection is slow and offers an action, a retry unless told otherwise.
struct SlowHint: View {
    var actionTitle: LocalizedStringKey = "common.retry"
    let retry: () -> Void
    @State private var isSlow = false

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            if isSlow {
                Text("common.slow").font(.yak(.footnote)).foregroundStyle(Color.yakMuted)
                Button(actionTitle, action: retry).buttonStyle(SecondaryButtonStyle())
            }
        }
        .task {
            do {
                try await Task.sleep(for: .seconds(8))
                withAnimation(.spring) { isSlow = true }
            } catch {}
        }
    }
}
