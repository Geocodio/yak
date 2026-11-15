import SwiftUI
import YakKit

/// Placeholder home until the drawer of the next plan: who is signed in, and the instance actions.
struct SignedInView: View {
    let model: AppModel
    let instance: Instance
    /// Set when the screen is a sheet, so it can be closed.
    var onClose: (() -> Void)?

    @Environment(\.scenePhase) private var scenePhase
    @State private var showsAdd = false
    @State private var confirmsRemove = false
    @State private var showsRefreshing = false

    var body: some View {
        NavigationStack {
            List {
                Section {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(instance.name).font(.title2.bold())
                        Text("signedIn.signedInAs \(instance.userName)").foregroundStyle(Color.yakMuted)
                    }
                    .padding(.vertical, 4)
                    .accessibilityElement(children: .combine)
                    Label(
                        instance.pushAvailable ? "signedIn.pushOn" : "signedIn.pushOff",
                        systemImage: instance.pushAvailable ? "bell" : "bell.slash")
                }
                if !model.network.isOnline {
                    Section {
                        HStack(alignment: .firstTextBaseline, spacing: 12) {
                            Image(systemName: "wifi.slash").accessibilityHidden(true)
                            Text("common.offline")
                        }
                    }
                } else if model.refreshState == .loading && showsRefreshing {
                    Section { SlowHintRow { Task { await model.refreshMe(for: instance) } } }
                }
                if let message = failureMessage, model.network.isOnline {
                    Section {
                        InstanceProblemView(message: message) { Task { await model.refreshMe(for: instance) } }
                    }
                }
                Section {
                    Button("signedIn.add") { showsAdd = true }.frame(minHeight: 44)
                    Button("signedIn.remove", role: .destructive) { confirmsRemove = true }
                        .foregroundStyle(Color.yakDanger)
                        .frame(minHeight: 44)
                }
            }
            .tint(Color.yakAction)
            // The list keeps a readable width on a wide screen, like the pairing screens.
            .frame(maxWidth: 640)
            .frame(maxWidth: .infinity)
            .background(Color(.systemGroupedBackground))
            .navigationTitle("signedIn.title")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if let onClose {
                    ToolbarItem(placement: .cancellationAction) { Button("common.close", action: onClose) }
                }
            }
        }
        .task(id: instance.id) { await model.refreshMe(for: instance) }
        .task(id: model.refreshState == .loading) {
            // Nothing for the first 300 ms, so a quick answer does not make the list jump.
            guard model.refreshState == .loading else {
                showsRefreshing = false
                return
            }
            do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
            showsRefreshing = true
        }
        .onChange(of: model.network.isOnline) { _, isOnline in
            if isOnline && model.refreshState != .idle && model.refreshState != .loading {
                Task { await model.refreshMe(for: instance) }
            }
        }
        .onChange(of: instance.id) { showsAdd = false }
        .onChange(of: model.wantsScanner) { _, wantsScanner in
            if wantsScanner { showsAdd = true }
        }
        .onChange(of: scenePhase) { _, phase in
            // The Keychain may be readable again once the device is unlocked.
            if phase == .active && model.refreshState == .tokenUnreadable {
                Task { await model.refreshMe(for: instance) }
            }
        }
        .sheet(isPresented: $showsAdd) {
            NavigationStack {
                ConnectView(model: model, onDone: { showsAdd = false })
                    .linkPairingStatus(model, sheetDepth: 2)
                    .countsAsOpenSheet(model)
                    .toolbar {
                        ToolbarItem(placement: .cancellationAction) {
                            Button("address.cancel") { showsAdd = false }
                        }
                    }
            }
        }
        .confirmationDialog(
            Text("signedIn.removeTitle \(instance.name)"),
            isPresented: $confirmsRemove, titleVisibility: .visible
        ) {
            Button("signedIn.removeConfirm", role: .destructive) {
                Task { await model.remove(instance) }
            }
        }
        .sensoryFeedback(.warning, trigger: confirmsRemove)
    }
}

extension SignedInView {
    fileprivate var failureMessage: LocalizedStringKey? {
        switch model.refreshState {
        case .idle, .loading: nil
        case .unreachable: "signedIn.unreachable \(instance.name)"
        case .appTooOld: "signedIn.appTooOld"
        case .serverTooOld: "signedIn.serverTooOld"
        case .tokenUnreadable: "signedIn.tokenUnreadable"
        case .failed: "signedIn.failed"
        }
    }
}

/// A "refreshing" row that says the connection is slow after 8 seconds.
private struct SlowHintRow: View {
    let retry: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 10) {
                ProgressView().accessibilityHidden(true)
                Text("signedIn.refreshing")
            }
            SlowHint(retry: retry)
        }
    }
}
