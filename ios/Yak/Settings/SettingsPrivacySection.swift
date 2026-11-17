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
            deviceRow
        }
        .task(id: "\(active.id)-\(app.refreshState)") { await loadDevice() }
        .sensoryFeedback(.error, trigger: lock.toggle == .failed)
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
        .disabled(lock.method == nil || confirming)
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
