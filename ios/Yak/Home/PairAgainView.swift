import SwiftUI
import YakKit

/// Shown when an instance is listed but its token is gone or was revoked.
struct PairAgainView: View {
    let model: AppModel
    let instance: Instance

    @State private var showsPairing = false
    @State private var showsScanner = false
    @State private var confirmsRemove = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                Image(systemName: "iphone.slash")
                    .font(.system(size: 44, weight: .light))
                    .foregroundStyle(.secondary)
                    .accessibilityHidden(true)
                Text("pairAgain.title \(instance.name)").font(.largeTitle.bold())
                Text("pairAgain.body").font(.body).foregroundStyle(.secondary)
            }
            .frame(maxWidth: 560, alignment: .leading)
            .frame(maxWidth: .infinity)
            .padding(24)
        }
        .safeAreaInset(edge: .bottom) {
            VStack(spacing: 12) {
                Button("pairAgain.pair") { showsPairing = true }.buttonStyle(PrimaryButtonStyle())
                Button("pairAgain.remove", role: .destructive) { confirmsRemove = true }
                    .buttonStyle(SecondaryButtonStyle())
            }
            .frame(maxWidth: 560)
            .padding(.horizontal, 24)
            .padding(.vertical, 12)
            .frame(maxWidth: .infinity)
            .background(.bar)
        }
        .onChange(of: model.wantsScanner, initial: true) { _, wantsScanner in
            // The scanner sheet's ConnectView takes the request and clears the flag.
            if wantsScanner { showsScanner = true }
        }
        .sheet(isPresented: $showsScanner) {
            NavigationStack {
                ConnectView(model: model, onDone: { showsScanner = false })
                    .linkPairingStatus(model)
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
                    model: model, initialAddress: instance.url.absoluteString.replacing("https://", with: ""),
                    onDone: { showsPairing = false }
                )
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
