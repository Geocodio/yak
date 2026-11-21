import SwiftUI
import YakKit

/// The words of the privacy rows, as plain functions so a test can read every form.
enum PrivacyRows {
    static func lockTitle(_ method: AppLockMethod?) -> String {
        switch method {
        case .faceID?: String(localized: "privacy.lock.faceID")
        case .touchID?: String(localized: "privacy.lock.touchID")
        case .opticID?: String(localized: "privacy.lock.opticID")
        case .deviceUnlock?: String(localized: "privacy.lock.device")
        case .passcode?, nil: String(localized: "privacy.lock.passcode")
        }
    }

    /// With no known method the lock cannot be turned on, but one that is on can always be turned off.
    static func lockSwitchIsDisabled(method: AppLockMethod?, isOn: Bool, confirming: Bool) -> Bool {
        confirming || (method == nil && !isOn)
    }

    static func lockSubtitle(hasMethod: Bool) -> String {
        hasMethod ? String(localized: "privacy.lock.subtitle") : String(localized: "privacy.lock.noPasscode")
    }

    /// The stored choice is kept while the lock is on, but the switch shows off.
    static func spotlightSwitchValue(stored: Bool, lockIsOn: Bool) -> Bool { stored && !lockIsOn }

    static func spotlightSubtitle(includesPrivate: Bool, lockIsOn: Bool) -> String {
        if lockIsOn { return String(localized: "privacy.spotlight.locked") }
        return includesPrivate
            ? String(localized: "privacy.spotlight.on") : String(localized: "privacy.spotlight.off")
    }

    static func bytes(_ count: Int) -> String {
        ByteCountFormatter.string(fromByteCount: Int64(count), countStyle: .file)
    }

    static func copiesSummary(_ summary: OfflineCopySummary) -> String {
        String(localized: "privacy.copies.summary \(summary.threads) \(bytes(summary.bytes))")
    }

    /// The count line under the title; a failed clear shows its error on a line of its own above.
    static func copiesDetail(_ row: OfflineCopiesRow) -> String? {
        if case .failed(let summary) = row { return summary.isEmpty ? nil : copiesSummary(summary) }
        return copiesSubtitle(row)
    }

    /// Everything the row says, in one line for VoiceOver.
    static func copiesSubtitle(_ row: OfflineCopiesRow) -> String {
        switch row {
        case .counting: String(localized: "privacy.copies.counting")
        case .saved(let summary): copiesSummary(summary)
        case .empty: String(localized: "privacy.copies.none")
        case .clearing: String(localized: "privacy.copies.clearing")
        case .cleared: String(localized: "privacy.copies.cleared")
        case .failed(let summary):
            summary.isEmpty
                ? String(localized: "privacy.copies.failed")
                : "\(String(localized: "privacy.copies.failed")) \(copiesSummary(summary))"
        }
    }

    static func copiesFooter(limits: OfflineCopyLimits = .standard) -> String {
        String(localized: "privacy.copies.footer \(limits.maximumThreads) \(bytes(limits.maximumTotalBytes))")
    }

    /// `hasLoaded` is false until `me` answered.
    static func deviceSubtitle(line: ThisDeviceLine, hasLoaded: Bool, locale: Locale = .current) -> String {
        guard hasLoaded else { return String(localized: "privacy.device.beforeMe") }
        guard let date = line.pairedAt else { return String(localized: "privacy.device.noDate") }
        let formatted = date.formatted(.dateTime.day().month(.abbreviated).locale(locale))
        return String(localized: "privacy.device.paired \(formatted)")
    }

    static func versionText(short: String?, build: String?) -> String {
        guard let short, !short.isEmpty, let build, !build.isEmpty else {
            return String(localized: "about.version.unknown")
        }
        return String(localized: "about.version \(short) \(build)")
    }

    static func showsDebugRow(kind: DebugBuildKind?) -> Bool { kind.map(DebugMenuAvailability.isAvailable) ?? false }

    static func debugSubtitle(fault: DebugFault) -> String {
        let name =
            switch fault {
            case .off: String(localized: "debug.fault.off")
            case .slow: String(localized: "debug.fault.slow")
            case .verySlow: String(localized: "debug.fault.verySlow")
            case .offline: String(localized: "debug.fault.offline")
            case .serverError: String(localized: "debug.fault.serverError")
            }
        return String(localized: "about.debug.requests \(name)")
    }
}

/// App lock, private threads in Spotlight and "This device".
struct SettingsPrivacySection: View {
    let app: AppModel
    let active: Instance
    @Bindable var model: SettingsModel
    @Environment(SystemFeatures.self) private var system
    @State private var line = ThisDeviceLine.make(device: nil)
    @State private var hasLoaded = false

    var body: some View {
        let lock = system.appLock
        Section {
            lockRow(lock)
            if lock.toggle == .failed {
                note("privacy.lock.failed", identifier: "settings.appLock.failed")
            }
            if lock.policy.turnedOffForMissingPasscode {
                note("privacy.lock.turnedOff", identifier: "settings.appLock.turnedOff")
            }
            spotlightRow(lock)
            if system.spotlight.state == .failed {
                note("privacy.spotlight.failed", identifier: "settings.spotlightPrivate.failed")
            }
            copiesRow
            deviceRow
        } footer: {
            Text(verbatim: PrivacyRows.copiesFooter()).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true)
                .accessibilityIdentifier("settings.offlineCopies.footer")
        }
        .task(id: "\(active.id)-\(app.refreshState)") { await loadDevice() }
        .task { await model.loadOfflineCopies() }
        .confirmationDialog(
            Text("privacy.copies.confirmTitle"), isPresented: $model.isConfirmingClear, titleVisibility: .visible
        ) {
            Button("privacy.copies.confirmButton \(copyCount)", role: .destructive) {
                Task { await model.clearOfflineCopies() }
            }
        } message: {
            Text("privacy.copies.confirmMessage \(copyCount)")
        }
        .onChange(of: model.clearedCount) {
            AccessibilityNotification.Announcement(String(localized: "privacy.copies.announcement")).post()
        }
    }

    private var copyCount: Int {
        switch model.offlineCopies {
        case .saved(let summary), .failed(let summary): summary.threads
        case .counting, .empty, .clearing, .cleared: 0
        }
    }

    private var copiesRow: some View {
        let row = model.offlineCopies
        let canClear = row.canClear
        return Button {
            model.askToClearOfflineCopies()
        } label: {
            VStack(alignment: .leading, spacing: Design.Space.nudge) {
                Text("privacy.copies.title").foregroundStyle(canClear ? Color.yakAction : Color.yakMuted)
                if case .failed = row {
                    Text("privacy.copies.failed").font(.footnote.weight(.medium)).foregroundStyle(Color.yakDanger)
                        .fixedSize(horizontal: false, vertical: true)
                }
                if row == .cleared {
                    HStack(alignment: .firstTextBaseline, spacing: Design.Space.tight) {
                        Image(systemName: "checkmark").font(.footnote.weight(.semibold)).foregroundStyle(Color.yakOK)
                            .accessibilityHidden(true)
                        subtitle(PrivacyRows.copiesSubtitle(row))
                    }
                } else if let detail = PrivacyRows.copiesDetail(row) {
                    subtitle(detail)
                }
            }
            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
            .contentShape(.rect)
        }
        .buttonStyle(.plain)
        .disabled(!canClear)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(
            "\(String(localized: "privacy.copies.title")), \(PrivacyRows.copiesSubtitle(row))"
        )
        .accessibilityIdentifier("settings.offlineCopies")
    }

    private func lockRow(_ lock: AppLockController) -> some View {
        let confirming = lock.toggle == .confirming
        return Toggle(
            isOn: Binding(
                get: { lock.policy.isOn || confirming },
                set: { isOn in Task { await system.setAppLock(isOn) } })
        ) {
            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 8) {
                    Text(verbatim: PrivacyRows.lockTitle(lock.method))
                    if confirming { ProgressView().accessibilityHidden(true) }
                }
                subtitle(PrivacyRows.lockSubtitle(hasMethod: lock.method != nil))
            }
            .frame(minHeight: 44, alignment: .leading)
        }
        .disabled(PrivacyRows.lockSwitchIsDisabled(method: lock.method, isOn: lock.policy.isOn, confirming: confirming))
        .accessibilityIdentifier("settings.appLock")
    }

    private func spotlightRow(_ lock: AppLockController) -> some View {
        let lockIsOn = lock.policy.isOn
        let updating = system.spotlight.state == .updating
        return Toggle(
            isOn: Binding(
                get: {
                    PrivacyRows.spotlightSwitchValue(
                        stored: system.settings.spotlightIncludesPrivate, lockIsOn: lockIsOn)
                },
                set: { isOn in Task { await system.setSpotlightIncludesPrivate(isOn) } })
        ) {
            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 8) {
                    Text("privacy.spotlight.title")
                    if updating { ProgressView().accessibilityHidden(true) }
                }
                subtitle(
                    PrivacyRows.spotlightSubtitle(
                        includesPrivate: system.settings.spotlightIncludesPrivate, lockIsOn: lockIsOn))
            }
            .frame(minHeight: 44, alignment: .leading)
        }
        .disabled(lockIsOn || updating)
        .accessibilityIdentifier("settings.spotlightPrivate")
    }

    private var deviceRow: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text("privacy.device.title")
            subtitle(PrivacyRows.deviceSubtitle(line: line, hasLoaded: hasLoaded))
        }
        .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("settings.thisDevice")
    }

    private func subtitle(_ text: String) -> some View {
        Text(verbatim: text).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
            .fixedSize(horizontal: false, vertical: true)
    }

    private func note(_ key: LocalizedStringKey, identifier: String) -> some View {
        Text(key).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
            .fixedSize(horizontal: false, vertical: true)
            .accessibilityIdentifier(identifier)
    }

    /// What the session last read; no request is made here.
    private func loadDevice() async {
        let session = app.sessions.session(for: active)
        guard let me = await session.me() else { return }
        line = ThisDeviceLine.make(device: me.device)
        hasLoaded = true
    }
}
