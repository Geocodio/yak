import SwiftUI
import YakKit

/// The paired instances: pick one to switch to it, or add another. Opening it refreshes the others, so the
/// "need you" counts are fresh; closing it cancels the refreshes.
struct InstanceSwitcher: View {
    let model: AppModel
    let active: Instance
    let onClose: () -> Void

    init(model: AppModel, active: Instance, onClose: @escaping () -> Void) {
        self.model = model
        self.active = active
        self.onClose = onClose
        _switcher = State(
            initialValue: InstanceSwitchModel(
                activate: { try await model.instances.setActive($0) }, finish: { await model.reloadRoute() }))
    }

    @State private var instances: [Instance] = []
    @State private var showsAdd = false
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @State private var switcher: InstanceSwitchModel

    var body: some View {
        NavigationStack {
            List {
                Section {
                    ForEach(instances) { instance in row(instance) }
                } footer: {
                    if let failedName = switcher.failedName {
                        Text("switcher.switchFailed \(failedName)").font(.yak(.footnote)).foregroundStyle(.primary)
                            .accessibilityIdentifier("switcher.failed")
                    }
                }
                Section {
                    Button {
                        showsAdd = true
                    } label: {
                        Label("signedIn.add", systemImage: "plus").fixedSize(horizontal: false, vertical: true)
                            .foregroundStyle(Color.yakAction).frame(minHeight: 44, alignment: .leading)
                            .id(dynamicTypeSize)
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
                    .presentationBackground(Color.yakBackground)
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
        .sensoryFeedback(.selection, trigger: switcher.isSwitching)
    }

    private func row(_ instance: Instance) -> some View {
        Button {
            guard instance.id != active.id else {
                onClose()
                return
            }
            Task {
                if await switcher.switchTo(id: instance.id, name: instance.name) { onClose() }
            }
        } label: {
            HStack(spacing: 12) {
                InstanceTintTile(instance: instance)
                VStack(alignment: .leading, spacing: 2) {
                    Text(instance.name).font(.yak(.body)).foregroundStyle(Color.primary)
                        .fixedSize(horizontal: false, vertical: true)
                    Text(instance.url.host() ?? instance.url.absoluteString).font(.yak(.footnote, .medium))
                        .foregroundStyle(Color.yakMuted).fixedSize(horizontal: false, vertical: true)
                    Text(statusLine(instance)).font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                        .fixedSize(horizontal: false, vertical: true)
                }
                Spacer(minLength: 8)
                if switcher.switchingID == instance.id {
                    ProgressView().accessibilityHidden(true)
                } else if instance.id == active.id {
                    Image(systemName: "checkmark").foregroundStyle(Color.primary).accessibilityHidden(true)
                }
            }
            .frame(minHeight: 44)
            .contentShape(.rect)
            .id(dynamicTypeSize)
        }
        .buttonStyle(.pressable)
        .disabled(switcher.isSwitching)
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
        var parts = [instance.name, instance.url.host() ?? "", statusLine(instance)]
        if switcher.switchingID == instance.id { parts.append(String(localized: "switcher.switching")) }
        return parts.filter { !$0.isEmpty }.joined(separator: ". ")
    }
}
