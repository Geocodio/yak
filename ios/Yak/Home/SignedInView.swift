import SwiftUI
import YakKit

/// Placeholder home until the drawer of the next plan: who is signed in, and the instance actions.
struct SignedInView: View {
    let model: AppModel
    let instance: Instance

    @State private var showsAdd = false
    @State private var confirmsRemove = false

    var body: some View {
        NavigationStack {
            List {
                Section {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(instance.name).font(.title2.bold())
                        Text("signedIn.signedInAs \(instance.userName)").foregroundStyle(.secondary)
                    }
                    .padding(.vertical, 4)
                    .accessibilityElement(children: .combine)
                    Label(
                        instance.pushAvailable ? "signedIn.pushOn" : "signedIn.pushOff",
                        systemImage: instance.pushAvailable ? "bell" : "bell.slash")
                }
                if model.refreshState == .loading {
                    Section { SlowHintRow { Task { await model.refreshMe(for: instance) } } }
                }
                if model.refreshState == .failed {
                    Section {
                        Text("signedIn.refreshFailed \(instance.name)")
                        Button("common.retry") { Task { await model.refreshMe(for: instance) } }
                            .frame(minHeight: 44)
                    }
                }
                Section {
                    Button("signedIn.add") { showsAdd = true }.frame(minHeight: 44)
                    Button("signedIn.remove", role: .destructive) { confirmsRemove = true }
                        .frame(minHeight: 44)
                }
            }
            .navigationTitle("signedIn.title")
            .navigationBarTitleDisplayMode(.inline)
        }
        .task(id: instance.id) { await model.refreshMe(for: instance) }
        .sheet(isPresented: $showsAdd) {
            NavigationStack {
                ConnectView(model: model, onDone: { showsAdd = false })
                    .toolbar {
                        ToolbarItem(placement: .cancellationAction) {
                            Button("address.cancel") { showsAdd = false }
                        }
                    }
            }
        }
        .confirmationDialog(
            Text("signedIn.removeTitle \(instance.name) \(DeviceDescription.kindName)"),
            isPresented: $confirmsRemove, titleVisibility: .visible
        ) {
            Button("signedIn.removeConfirm", role: .destructive) {
                Task { await model.remove(instance) }
            }
        }
        .sensoryFeedback(.warning, trigger: confirmsRemove)
    }
}

/// A "refreshing" row that says the connection is slow after 8 seconds.
private struct SlowHintRow: View {
    let retry: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 10) {
                ProgressView()
                Text("signedIn.refreshing")
            }
            SlowHint(retry: retry)
        }
    }
}
