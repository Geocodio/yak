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

    /// The last loaded switches per instance in this run, shown at once while the next load runs.
    private static var savedPreferences: [UUID: NotificationPreferences] = [:]

    /// How long "Sent" shows before the row returns.
    static let sentDuration: Duration = .seconds(6)
    static let slowDelay: Duration = .seconds(8)

    @ObservationIgnored private let app: AppModel
    @ObservationIgnored private let push: PushController
    @ObservationIgnored private let registrar: PushRegistrar
    @ObservationIgnored private let clock: any YakClock
    @ObservationIgnored private let removeInstance: @MainActor (Instance) async -> Void
    @ObservationIgnored private var tasks: [String: Task<Void, Never>] = [:]

    init(
        app: AppModel, push: PushController, registrar: PushRegistrar, active: Instance, clock: any YakClock,
        removeInstance: (@MainActor (Instance) async -> Void)? = nil
    ) {
        self.app = app
        self.push = push
        self.registrar = registrar
        self.active = active
        self.clock = clock
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
