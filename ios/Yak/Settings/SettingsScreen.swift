import SwiftUI
import UIKit
import YakKit

/// Settings as a sheet: the instances, the notification switches, the iOS hints, the test notification and
/// this device.
struct SettingsScreen: View {
    let app: AppModel
    let onClose: () -> Void

    @State private var model: SettingsModel
    @State private var showsAdd = false
    @State private var showsRefreshing = false
    @State private var confirmingRemoval: Instance?
    @State private var turnedOn = false
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.openURL) private var openURL

    init(app: AppModel, push: PushController, active: Instance, onClose: @escaping () -> Void) {
        self.app = app
        self.onClose = onClose
        _model = State(
            initialValue: SettingsModel(
                app: app, push: push, registrar: push.registrar, active: active, clock: SystemClock()))
    }

    var body: some View {
        NavigationStack {
            List {
                header
                connection
                instancesSection
                NotificationSwitchesView(model: model)
                permission
                if model.canPush { TestNotificationRow(model: model) }
                deviceSection
            }
            .tint(Color.yakAction)
            .frame(maxWidth: 640)
            .frame(maxWidth: .infinity)
            .background(Color(.systemGroupedBackground))
            .navigationTitle("settings.title")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("common.close", action: onClose) }
            }
        }
        .accessibilityIdentifier("settings.screen")
        .task(id: model.active.id) { await model.start() }
        .onDisappear { model.stop() }
        .task(id: app.refreshState == .loading) {
            guard app.refreshState == .loading else {
                showsRefreshing = false
                return
            }
            do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
            showsRefreshing = true
        }
        .onChange(of: app.network.isOnline) { _, isOnline in
            guard isOnline else { return }
            Task { await model.networkReturned() }
            if app.refreshState != .idle && app.refreshState != .loading {
                Task { await app.refreshMe(for: model.active) }
            }
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active && app.refreshState == .tokenUnreadable {
                Task { await app.refreshMe(for: model.active) }
            }
        }
        .onChange(of: app.wantsScanner) { _, wantsScanner in
            if wantsScanner { showsAdd = true }
        }
        .sheet(isPresented: $showsAdd) {
            NavigationStack {
                ConnectView(model: app, onDone: { showsAdd = false })
                    .linkPairingStatus(app, sheetDepth: 2)
                    .countsAsOpenSheet(app)
                    .toolbar {
                        ToolbarItem(placement: .cancellationAction) {
                            Button("address.cancel") { showsAdd = false }
                        }
                    }
            }
        }
        .confirmationDialog(
            Text("signedIn.removeTitle \(confirmingRemoval?.name ?? "")"),
            isPresented: Binding(get: { confirmingRemoval != nil }, set: { if !$0 { confirmingRemoval = nil } }),
            titleVisibility: .visible
        ) {
            Button("signedIn.removeConfirm", role: .destructive) {
                guard let instance = confirmingRemoval else { return }
                Task { await model.remove(instance) }
            }
        }
        .sensoryFeedback(.warning, trigger: confirmingRemoval?.id)
        .sensoryFeedback(.success, trigger: turnedOn)
    }

    // MARK: Header and connection

    private var header: some View {
        Section {
            Text("signedIn.signedInAs \(model.active.userName)").foregroundStyle(Color.yakMuted)
                .frame(minHeight: 44, alignment: .leading)
        }
    }

    @ViewBuilder private var connection: some View {
        if !app.network.isOnline {
            Section {
                HStack(alignment: .firstTextBaseline, spacing: 12) {
                    Image(systemName: "wifi.slash").accessibilityHidden(true)
                    Text("common.offline")
                }
            }
        } else if app.refreshState == .loading && showsRefreshing {
            Section {
                VStack(alignment: .leading, spacing: 8) {
                    HStack(spacing: 10) {
                        ProgressView().accessibilityHidden(true)
                        Text("signedIn.refreshing")
                    }
                    SlowHint { Task { await app.refreshMe(for: model.active) } }
                }
            }
        }
        if let message = failureMessage, app.network.isOnline {
            Section {
                InstanceProblemView(message: message) { Task { await app.refreshMe(for: model.active) } }
            }
        }
    }

    private var failureMessage: LocalizedStringKey? {
        switch app.refreshState {
        case .idle, .loading: nil
        case .unreachable: "signedIn.unreachable \(model.active.name)"
        case .appTooOld: "signedIn.appTooOld"
        case .serverTooOld: "signedIn.serverTooOld"
        case .tokenUnreadable: "signedIn.tokenUnreadable"
        case .failed: "signedIn.failed"
        }
    }

    // MARK: Instances

    private var instancesSection: some View {
        Section {
            ForEach(model.instances) { instance in row(instance) }
            if let failedName = model.switchFailedName {
                Text("switcher.switchFailed \(failedName)").foregroundStyle(Color.yakDanger)
                    .accessibilityIdentifier("settings.switchFailed")
            }
            Button {
                showsAdd = true
            } label: {
                Text("signedIn.add").frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
            }
            .accessibilityIdentifier("settings.addInstance")
            Button(role: .destructive) {
                confirmingRemoval = model.current
            } label: {
                Text("signedIn.remove").frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
            }
            .foregroundStyle(Color.yakDanger)
            .disabled(model.removing.contains(model.active.id))
            .accessibilityIdentifier("settings.removeActive")
        } header: {
            Text("settings.instances")
        }
    }

    private func row(_ instance: Instance) -> some View {
        let isActive = instance.id == model.active.id
        let isRemoving = model.removing.contains(instance.id)
        return Button {
            guard !isActive else { return }
            Task { if await model.switchTo(instance) { onClose() } }
        } label: {
            HStack(spacing: 12) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(instance.name).font(.body).foregroundStyle(Color.primary)
                        .fixedSize(horizontal: false, vertical: true)
                    Text(isRemoving ? String(localized: "settings.removing") : model.subtitle(of: instance))
                        .font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                        .fixedSize(horizontal: false, vertical: true)
                }
                Spacer(minLength: 8)
                if isRemoving {
                    ProgressView().accessibilityHidden(true)
                } else if isActive {
                    Image(systemName: "checkmark").foregroundStyle(Color.yakAction).accessibilityHidden(true)
                } else {
                    Image(systemName: "chevron.right").font(.footnote.weight(.semibold))
                        .foregroundStyle(Color.yakMuted).accessibilityHidden(true)
                }
            }
            .frame(minHeight: 44)
            .contentShape(.rect)
        }
        .buttonStyle(.plain)
        .disabled(isRemoving)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(model.accessibilityLabel(of: instance))
        .accessibilityAddTraits(isActive ? .isSelected : [])
        .accessibilityIdentifier("settings.instance.\(model.host(of: instance))")
        .swipeActions(edge: .trailing) {
            Button("settings.remove", role: .destructive) { confirmingRemoval = instance }
        }
        .contextMenu {
            Button("settings.remove", role: .destructive) { confirmingRemoval = instance }
        }
    }

    // MARK: Permission

    @ViewBuilder private var permission: some View {
        let hint = model.hint
        if hint != nil || model.showsLiveActivitiesOff {
            Section {
                switch hint {
                case .turnOn?:
                    VStack(alignment: .leading, spacing: 12) {
                        Text("settings.permission.notAsked")
                        Button("settings.permission.turnOn") {
                            Task {
                                await model.turnOnNotifications()
                                turnedOn.toggle()
                            }
                        }
                        .buttonStyle(SecondaryButtonStyle())
                        .accessibilityIdentifier("settings.permission")
                    }
                case .offInSystemSettings?:
                    systemSettingsRow("settings.permission.off", identifier: "settings.openSystemSettings")
                case nil: EmptyView()
                }
                if model.showsLiveActivitiesOff {
                    systemSettingsRow("settings.liveActivitiesOff", identifier: "settings.liveActivitiesOff")
                }
            } header: {
                Text("settings.permission.header")
            }
        }
    }

    private func systemSettingsRow(_ text: LocalizedStringKey, identifier: String) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(text)
            Button("settings.openSystemSettings") {
                if let url = URL(string: UIApplication.openSettingsURLString) { openURL(url) }
            }
            .buttonStyle(SecondaryButtonStyle())
        }
        .accessibilityIdentifier(identifier)
    }

    // MARK: This device

    private var deviceSection: some View {
        Section {
            VStack(alignment: .leading, spacing: 2) {
                Text("settings.thisDevice \(UIDevice.current.model)")
                if let pairedAt = app.devicePairedAt[model.active.id] {
                    Text("settings.device.paired \(pairedAt.formatted(.dateTime.day().month(.abbreviated)))")
                        .font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                } else {
                    Text("settings.device.pairedUnknown").font(.footnote.weight(.medium))
                        .foregroundStyle(Color.yakMuted)
                }
            }
            .frame(minHeight: 44, alignment: .leading)
            .accessibilityElement(children: .combine)
            .accessibilityIdentifier("settings.device")
        }
    }
}
