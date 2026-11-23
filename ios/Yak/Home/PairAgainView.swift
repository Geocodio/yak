import SwiftUI
import YakKit

/// Shown when an instance is listed but its token is gone or was revoked.
struct PairAgainView: View {
    let model: AppModel
    let instance: Instance

    @State private var showsPairing = false
    @State private var showsScanner = false
    @State private var confirmsRemove = false

    /// The saved address as a person types it: host and port, no scheme.
    private var typedAddress: String {
        guard let host = instance.url.host() else { return "" }
        return instance.url.port.map { "\(host):\($0)" } ?? host
    }

    var body: some View {
        ActionPage {
            Image(systemName: "xmark.circle")
                .font(.system(size: 44, weight: .light))
                .foregroundStyle(Color.yakMuted)
                .accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 16) {
                Text("pairAgain.title \(instance.name)").font(.yak(.largeTitle, .bold))
                Text("pairAgain.body").font(.yak(.body)).foregroundStyle(Color.yakMuted)
            }
        } actions: {
            Button("pairAgain.pair") { showsPairing = true }.buttonStyle(PrimaryButtonStyle())
            Button("pairAgain.remove", role: .destructive) { confirmsRemove = true }
                .buttonStyle(SecondaryButtonStyle())
        }
        .onChange(of: model.wantsScanner, initial: true) { _, wantsScanner in
            // The scanner sheet's ConnectView takes the request and clears the flag.
            if wantsScanner { showsScanner = true }
        }
        .sheet(isPresented: $showsScanner) {
            NavigationStack {
                ConnectView(model: model, onDone: { showsScanner = false })
                    .linkPairingStatus(model, sheetDepth: 1)
                    .countsAsOpenSheet(model)
                    .toolbar {
                        ToolbarItem(placement: .cancellationAction) {
                            Button("address.cancel") { showsScanner = false }
                        }
                    }
            }
        }
        .sheet(isPresented: $showsPairing) {
            NavigationStack {
                EnterAddressView(
                    model: model, initialAddress: typedAddress,
                    onDone: { showsPairing = false }
                )
                .linkPairingStatus(model, sheetDepth: 1)
                .countsAsOpenSheet(model)
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("address.cancel") { showsPairing = false }
                    }
                }
            }
        }
        .confirmationDialog(
            Text("signedIn.removeTitle \(instance.name)"),
            isPresented: $confirmsRemove, titleVisibility: .visible
        ) {
            Button("signedIn.removeConfirm", role: .destructive) { Task { await model.remove(instance) } }
        }
        .sensoryFeedback(.warning, trigger: confirmsRemove)
    }
}
