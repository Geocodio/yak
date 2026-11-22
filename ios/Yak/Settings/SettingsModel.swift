import Foundation
import Observation
import YakKit

/// The Settings sheet: the instances with their push state, the four notification switches, the test
/// notification and the removal of an instance. Every request of the screen is cancelled by `stop()`.
@MainActor @Observable
final class SettingsModel {
    enum TestState: Equatable {
        case idle, sending, sent
        case failed(PushProblem)
    }

    private(set) var instances: [Instance] = []
    private(set) var switches: NotificationSwitches
    /// True when the switches have loaded nothing for 8 seconds.
    private(set) var isSlow = false
    private(set) var test: TestState = .idle
    /// Instances being removed; a row in this set shows "Removing" and cannot be removed again.
    private(set) var removing: Set<UUID> = []
    /// The name of the instance the last switch failed for.
    private(set) var switchFailedName: String?
    /// Counts refused saves and tests, so the screen can play the error haptic once per refusal.
    private(set) var refusalCount = 0
    let active: Instance
    /// The saved thread copies row of the privacy group.
    private(set) var offlineCopies: OfflineCopiesRow = .counting
    /// True while the confirmation for clearing the copies is up.
    var isConfirmingClear = false
    /// Counts completed clears, so the screen can announce each one once.
    private(set) var clearedCount = 0
    /// Counts clears that failed, so the screen can announce each one once.
    private(set) var failedClearCount = 0

    /// The last loaded switches per instance in this run, shown at once while the next load runs.
    private static var savedPreferences: [UUID: NotificationPreferences] = [:]

    /// How long "Sent" shows before the row returns.
    static let sentDuration: Duration = .seconds(6)
    static let slowDelay: Duration = .seconds(8)

    @ObservationIgnored private let app: AppModel
    @ObservationIgnored private let push: PushController
    @ObservationIgnored private let registrar: PushRegistrar
    @ObservationIgnored private let clock: any YakClock
    @ObservationIgnored private let copyFiles: any FileStore
    @ObservationIgnored private let removeInstance: @MainActor (Instance) async -> Void
    @ObservationIgnored private var tasks: [String: Task<Void, Never>] = [:]

    init(
        app: AppModel, push: PushController, registrar: PushRegistrar, active: Instance, clock: any YakClock,
        removeInstance: (@MainActor (Instance) async -> Void)? = nil, copyFiles: (any FileStore)? = nil
    ) {
        self.app = app
        self.push = push
        self.registrar = registrar
        self.active = active
        self.clock = clock
        self.copyFiles = copyFiles ?? app.sessions.files
        self.removeInstance = removeInstance ?? { await app.remove($0) }
        self.switches = NotificationSwitches(saved: Self.savedPreferences[active.id])
    }

    // MARK: State the screen reads

    /// The active instance as stored now, so a refreshed availability shows.
    var current: Instance { instances.first { $0.id == active.id } ?? active }

    var canPush: Bool { current.push == .available }

    var anyInstanceCanPush: Bool { instances.contains { $0.push == .available } }

    func status(of instance: Instance) -> PushRegistrationStatus? { push.statuses[instance.id] }

    func host(of instance: Instance) -> String { instance.url.host() ?? instance.url.absoluteString }

    func subtitle(of instance: Instance) -> String {
        SettingsText.subtitle(
            status: status(of: instance), availability: instance.push, host: host(of: instance))
    }

    /// "<name>, <host>, <subtitle>"; the host is not said twice where the subtitle already names it.
    func accessibilityLabel(of instance: Instance) -> String {
        let subtitle = subtitle(of: instance)
        let host = host(of: instance)
        var parts = [instance.name]
        if !subtitle.contains(host) { parts.append(host) }
        parts.append(subtitle)
        if removing.contains(instance.id) { parts.append(String(localized: "settings.removing")) }
        return parts.joined(separator: ", ")
    }

    /// The reason the active instance cannot push, for the switches group.
    var unavailableReason: String {
        switch current.push {
        case nil, .available?: String(localized: "settings.subtitle.noKey")
        case .some(let reason): SettingsText.unavailable(reason, host: host(of: current))
        }
    }

    var hint: NotificationSettingsHint? {
        NotificationOnboarding.settingsHint(authorization: push.authorization, anyInstanceCanPush: anyInstanceCanPush)
    }

    var showsLiveActivitiesOff: Bool { !push.liveActivitiesEnabled && anyInstanceCanPush }

    // MARK: Loading

    /// Loads the instances, the active instance's `me` and its switches.
    func start() async {
        instances = await app.instances.all()
        async let refreshed: Void = app.refreshMe(for: active)
        await loadSwitches()
        await refreshed
        if Task.isCancelled { return }
        instances = await app.instances.all()
    }

    /// Cancels every request of the screen.
    func stop() {
        for task in tasks.values { task.cancel() }
        tasks = [:]
    }

    func retry() async {
        await loadSwitches()
    }

    /// The network came back: a load that failed for lack of it runs again by itself.
    func networkReturned() async {
        if case .failed = switches.load { await loadSwitches() }
    }

    private func loadSwitches() async {
        guard canPush else { return }
        isSlow = false
        await track("load") { [self] in
            let timer = Task { [clock] in
                do { try await clock.sleep(for: Self.slowDelay) } catch { return }
                if case .loading = switches.load { isSlow = true }
            }
            defer { timer.cancel() }
            switches = NotificationSwitches(saved: Self.savedPreferences[active.id])
            do throws(PushProblem) {
                let answer = try await registrar.preferences(instance: active.id)
                if Task.isCancelled { return }
                switches.loaded(answer)
                Self.savedPreferences[active.id] = answer
            } catch {
                if Task.isCancelled { return }
                switches.loadFailed(error)
            }
            isSlow = false
        }
    }

    // MARK: Switches

    /// Saves one switch. A second toggle of the same switch while it saves sends nothing.
    func toggle(_ event: PushEvent, to isOn: Bool) async {
        guard canPush, case .loaded = switches.load, switches.toggle(event, to: isOn) != nil else { return }
        await track("save.\(event.rawValue)") { [self] in
            do throws(PushProblem) {
                let answer = try await registrar.setPreference(event, isOn: isOn, instance: active.id)
                if Task.isCancelled { return }
                switches.saved(answer, for: event)
                Self.savedPreferences[active.id] = answer
            } catch {
                if Task.isCancelled { return }
                switches.saveFailed(event, problem: error)
                refusalCount += 1
            }
        }
    }

    // MARK: Offline copies

    /// Counts the saved copies of every paired instance from disk, off the main actor. A count that finds copies
    /// replaces "Cleared": the row never says cleared while a copy exists.
    func loadOfflineCopies() async {
        let summary = await recountOfflineCopies()
        if Task.isCancelled { return }
        switch offlineCopies {
        case .counting, .saved, .empty: offlineCopies = OfflineCopiesRow.counted(summary)
        case .cleared: if !summary.isEmpty { offlineCopies = .saved(summary) }
        case .failed: offlineCopies = .failed(summary)
        case .clearing: break
        }
    }

    func askToClearOfflineCopies() {
        guard offlineCopies.canClear else { return }
        isConfirmingClear = true
    }

    /// Removes the copies of every paired instance, one at a time on the main actor. The session check and the folder
    /// removal of an instance without a session have no suspension point between them, so a session that starts during
    /// the clear never has its folder deleted under it. The row says "Cleared" only when a recount finds nothing left:
    /// a capture that finished during the clear shows as the copies it left, and a refused removal as an error.
    func clearOfflineCopies() async {
        isConfirmingClear = false
        guard offlineCopies.canClear else { return }
        offlineCopies = .clearing
        let ids = await app.instances.all().map(\.id)
        var failed: [UUID] = []
        for id in ids {
            if let session = app.sessions.existingSession(for: id) {
                if await !session.offlineCopies().removeAll() { failed.append(id) }
            } else {
                do { try copyFiles.remove(InstanceFolders.offlineCopies(id)) } catch { failed.append(id) }
            }
        }
        if failed.count < ids.count { NotificationCenter.default.post(name: .offlineCopiesCleared, object: nil) }
        let remaining = await recountOfflineCopies(of: ids)
        if !failed.isEmpty {
            offlineCopies = .failed(remaining)
            failedClearCount += 1
        } else if remaining.isEmpty {
            offlineCopies = .cleared
            clearedCount += 1
        } else {
            offlineCopies = .saved(remaining)
        }
    }

    private func recountOfflineCopies(of known: [UUID]? = nil) async -> OfflineCopySummary {
        let ids: [UUID]
        if let known { ids = known } else { ids = await app.instances.all().map(\.id) }
        let files = copyFiles
        return await Task.detached { OfflineCopyStore.summary(of: ids, files: files) }.value
    }

    // MARK: Test notification

    func sendTest() async {
        guard canPush, test != .sending else { return }
        test = .sending
        await track("test") { [self] in
            do throws(PushProblem) {
                try await registrar.sendTestNotification(instance: active.id)
                if Task.isCancelled { return }
                test = .sent
                tasks["testReset"] = Task { [clock] in
                    do { try await clock.sleep(for: Self.sentDuration) } catch { return }
                    if test == .sent { test = .idle }
                }
            } catch {
                if Task.isCancelled { return }
                test = .failed(error)
                refusalCount += 1
            }
        }
    }

    // MARK: Instances and permission

    func turnOnNotifications() async {
        _ = await push.requestPermission()
    }

    /// Makes `instance` the active one; false when it failed, with the name kept for the line under the list.
    func switchTo(_ instance: Instance) async -> Bool {
        guard instance.id != active.id else { return true }
        switchFailedName = nil
        do {
            try await app.instances.setActive(instance.id)
        } catch {
            switchFailedName = instance.name
            return false
        }
        await app.reloadRoute()
        return true
    }

    /// Tells the server first and then forgets the instance; offline the instance goes anyway.
    func remove(_ instance: Instance) async {
        guard !removing.contains(instance.id) else { return }
        removing.insert(instance.id)
        await removeInstance(instance)
        removing.remove(instance.id)
        instances = await app.instances.all()
    }

    func refreshInstances() async {
        instances = await app.instances.all()
    }

    private func track(_ key: String, _ work: @escaping @MainActor () async -> Void) async {
        let task = Task { await work() }
        tasks[key]?.cancel()
        tasks[key] = task
        await withTaskCancellationHandler {
            await task.value
        } onCancel: {
            task.cancel()
        }
    }
}

/// What the "Clear offline copies" row shows and whether it can be tapped.
enum OfflineCopiesRow: Equatable {
    case counting
    case saved(OfflineCopySummary)
    case empty
    case clearing
    /// Cleared in this visit.
    case cleared
    /// Some copies could not be removed; the summary is what remains.
    case failed(OfflineCopySummary)

    static func counted(_ summary: OfflineCopySummary) -> OfflineCopiesRow {
        summary.isEmpty ? .empty : .saved(summary)
    }

    /// The number the confirmation names; nil when no count is known, which asks without one.
    var confirmCount: Int? {
        switch self {
        case .saved(let summary): summary.threads
        case .failed(let summary): summary.isEmpty ? nil : summary.threads
        case .counting, .empty, .clearing, .cleared: nil
        }
    }

    var canClear: Bool {
        switch self {
        case .saved, .failed(_): true
        case .counting, .empty, .clearing, .cleared: false
        }
    }
}

extension Notification.Name {
    /// Posted after the person cleared the offline copies, so open read-only views fall back at once.
    static let offlineCopiesCleared = Notification.Name("offlineCopiesCleared")
}
