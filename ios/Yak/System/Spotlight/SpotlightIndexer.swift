import CoreSpotlight
import Foundation
import Observation
import UIKit
import YakKit

#if canImport(os)
    import os
#endif

/// Keeps the Spotlight index of cached, non-private thread titles for the active instance, and erases an instance's
/// entries when it is unpaired or revoked. The record that guards every write lives in the key-value store.
@MainActor @Observable
final class SpotlightIndexer: InstanceLifecycleObserver {
    enum State: Equatable { case idle, updating, failed }

    nonisolated static let recordKey = "spotlight.indexRecord"
    static let writeBound: Duration = .seconds(10)
    static let removalBound: Duration = .seconds(4)

    private(set) var state: State = .idle

    @ObservationIgnored private let writer: any SpotlightWriter
    @ObservationIgnored private let settings: PrivacySettings
    @ObservationIgnored private let appLock: AppLockController
    @ObservationIgnored private let store: any KeyValueStore
    @ObservationIgnored private let instances: InstanceStore
    @ObservationIgnored private let activeLists:
        @MainActor () async -> (instance: SpotlightInstance, lists: [ThreadList])?
    @ObservationIgnored private let clock: any YakClock
    @ObservationIgnored private var isRunning = false
    @ObservationIgnored private var runAgain = false

    init(
        writer: any SpotlightWriter, settings: PrivacySettings, appLock: AppLockController,
        store: any KeyValueStore, instances: InstanceStore,
        activeLists: @escaping @MainActor () async -> (instance: SpotlightInstance, lists: [ThreadList])?,
        clock: any YakClock
    ) {
        self.writer = writer
        self.settings = settings
        self.appLock = appLock
        self.store = store
        self.instances = instances
        self.activeLists = activeLists
        self.clock = clock
    }

    // MARK: Runs

    /// At launch, when the app goes to the background and when either switch changes. Runs never overlap: a call
    /// while one runs asks for one more run and returns.
    func reindex() async {
        if isRunning {
            runAgain = true
            return
        }
        isRunning = true
        defer { isRunning = false }
        repeat {
            runAgain = false
            await runOnce()
        } while runAgain
    }

    /// The same run, inside a background task assertion that ends when the run ends.
    func reindexInBackground() async {
        let task = UIApplication.shared.beginBackgroundTask(withName: "spotlight")
        await reindex()
        if task != .invalid { UIApplication.shared.endBackgroundTask(task) }
    }

    private func runOnce() async {
        state = .updating
        var record = SpotlightIndexRecord.decode(store.data(forKey: Self.recordKey))
        let paired = await pairedIDs()
        let active = await activeLists()
        let lockIsOn = appLock.policy.isOn
        let privateAllowed = SpotlightIndexPlan.allowsPrivateTitles(
            includePrivate: settings.spotlightIncludesPrivate, appLockOn: lockIsOn)
        let changes = SpotlightIndexPlan.changes(
            record: record, paired: paired, active: active, includePrivate: settings.spotlightIncludesPrivate,
            appLockOn: lockIsOn, now: clock.now())
        for change in changes {
            record = SpotlightIndexPlan.recordingIntent(of: change, includePrivate: privateAllowed, in: record)
            save(record)
            let succeeded = await write(change, bound: Self.writeBound)
            record = SpotlightIndexPlan.record(
                record, after: change, succeeded: succeeded, includePrivate: privateAllowed)
            save(record)
            guard succeeded else {
                state = .failed
                return
            }
        }
        state = .idle
    }

    /// A revoked instance stays in the list with `.needsPairing`, and one whose token cannot be read is not paired
    /// either; both have their entries erased, which errs on the side of erasing.
    private func pairedIDs() async -> Set<UUID> {
        var ids = Set<UUID>()
        for instance in await instances.all() where await instances.state(of: instance.id) == .ready {
            ids.insert(instance.id)
        }
        return ids
    }

    private func save(_ record: SpotlightIndexRecord) { store.set(record.encoded(), forKey: Self.recordKey) }

    /// Applies one change, giving up after `bound`. The slow call is left to finish on its own.
    private func write(_ change: SpotlightChange, bound: Duration) async -> Bool {
        await withCheckedContinuation { (continuation: CheckedContinuation<Bool, Never>) in
            let gate = OneShot(continuation)
            let writer = self.writer
            let clock = self.clock
            let timer = Task {
                do { try await clock.sleep(for: bound) } catch { return }
                gate.finish(false)
            }
            gate.onFinish = { timer.cancel() }
            Task {
                let succeeded: Bool
                do {
                    try await writer.apply(change)
                    succeeded = true
                } catch {
                    #if canImport(os)
                        Logger(subsystem: "Yak", category: "Spotlight")
                            .error("Index change failed: \(String(describing: type(of: error)), privacy: .public)")
                    #endif
                    succeeded = false
                }
                gate.finish(succeeded)
            }
        }
    }

    // MARK: Instances

    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        var record = SpotlightIndexPlan.recordingRemoval(
            of: instance.id, in: SpotlightIndexRecord.decode(store.data(forKey: Self.recordKey)))
        save(record)
        let change = SpotlightChange.erase(domains: [instance.id])
        let succeeded = await write(change, bound: Self.removalBound)
        record = SpotlightIndexPlan.record(record, after: change, succeeded: succeeded, includePrivate: false)
        save(record)
    }

    // MARK: Continuing an item

    /// The item identifier Spotlight hands to the app, or nil for any other activity.
    static func identifier(of activity: NSUserActivity) -> String? {
        activity.userInfo?[CSSearchableItemActivityIdentifier] as? String
    }

    /// The thread link for an identifier this indexer made; nil for anything else.
    func link(forIdentifier identifier: String) async -> URL? {
        SpotlightIndexPlan.link(forIdentifier: identifier, instances: await instances.all())
    }

    func link(for activity: NSUserActivity) async -> URL? {
        guard let identifier = Self.identifier(of: activity) else { return nil }
        return await link(forIdentifier: identifier)
    }
}

/// Lets the first of two racing tasks resume the waiting caller.
private final class OneShot: @unchecked Sendable {
    private let lock = NSLock()
    private var continuation: CheckedContinuation<Bool, Never>?
    var onFinish: (@Sendable () -> Void)?

    init(_ continuation: CheckedContinuation<Bool, Never>) { self.continuation = continuation }

    func finish(_ value: Bool) {
        let taken = lock.withLock { () -> CheckedContinuation<Bool, Never>? in
            defer { continuation = nil }
            return continuation
        }
        guard let taken else { return }
        onFinish?()
        taken.resume(returning: value)
    }
}
