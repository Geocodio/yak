import SwiftUI
import YakKit

/// The paired instances: pick one to switch to it, or add another. Opening it refreshes the others, so the
/// "need you" counts are fresh; closing it cancels the refreshes.
struct InstanceSwitcher: View {
    let model: AppModel
    let active: Instance
    let onClose: () -> Void

    @State private var instances: [Instance] = []
    @State private var showsAdd = false
    @State private var isSwitching = false

    var body: some View {
        NavigationStack {
            List {
                Section {
                    ForEach(instances) { instance in row(instance) }
                }
                Section {
                    Button {
                        showsAdd = true
                    } label: {
                        Label("signedIn.add", systemImage: "plus").frame(minHeight: 44, alignment: .leading)
                    }
                    .accessibilityIdentifier("switcher.add")
                }
            }
            .navigationTitle("switcher.title")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("common.close", action: onClose) }
            }
        }
        .accessibilityIdentifier("switcher")
        .task {
            instances = await model.instances.all()
            // Each other instance asks for its own count, one after the other; leaving the sheet cancels them.
            for other in instances where other.id != active.id {
                if Task.isCancelled { return }
                await model.refreshMe(for: other)
                instances = await model.instances.all()
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
        .onChange(of: model.wantsScanner) { _, wantsScanner in
            if wantsScanner { showsAdd = true }
        }
        .sensoryFeedback(.selection, trigger: isSwitching)
    }

    private func row(_ instance: Instance) -> some View {
        Button {
            guard instance.id != active.id else {
                onClose()
                return
            }
            isSwitching = true
            Task {
                do { try await model.instances.setActive(instance.id) } catch {}
                await model.reloadRoute()
                onClose()
            }
        } label: {
            HStack(spacing: 12) {
                Text(String(instance.name.prefix(1)).uppercased()).font(.headline).foregroundStyle(.primary)
                    .frame(width: 40, height: 40).background(Color.primary.opacity(0.1), in: .rect(cornerRadius: 10))
                    .accessibilityHidden(true)
                VStack(alignment: .leading, spacing: 2) {
                    Text(instance.name).font(.body).foregroundStyle(.primary)
                    Text(instance.url.host() ?? instance.url.absoluteString).font(.footnote)
                        .foregroundStyle(Color.yakMuted)
                    Text(statusLine(instance)).font(.footnote).foregroundStyle(Color.yakMuted)
                }
                Spacer(minLength: 8)
                if instance.id == active.id {
                    Image(systemName: "checkmark").foregroundStyle(.primary).accessibilityHidden(true)
                }
            }
            .frame(minHeight: 44)
            .contentShape(.rect)
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(accessibilityLabel(instance))
        .accessibilityAddTraits(instance.id == active.id ? .isSelected : [])
        .accessibilityIdentifier("switcher.row.\(instance.name)")
    }

    private func statusLine(_ instance: Instance) -> String {
        if instance.needsYouCount > 0 { return String(localized: "switcher.needYou \(instance.needsYouCount)") }
        return instance.pushAvailable
            ? String(localized: "switcher.nothingWaiting") : String(localized: "switcher.noPush")
    }

    private func accessibilityLabel(_ instance: Instance) -> String {
        [instance.name, instance.url.host() ?? "", statusLine(instance)].filter { !$0.isEmpty }.joined(separator: ". ")
    }
}
