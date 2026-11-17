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
    @ObservationIgnored private var isBusy = false
    @ObservationIgnored private var waiters: [CheckedContinuation<Void, Never>] = []
    @ObservationIgnored private var abandoned: Task<Void, Never>?

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
    /// while one runs asks for one more run and returns. Runs and removals take turns, so a removal never overlaps
    /// a write in flight.
    func reindex() async {
        if isRunning {
            runAgain = true
            return
        }
        isRunning = true
        defer { isRunning = false }
        repeat {
            runAgain = false
            await acquire()
            await runOnce()
            release()
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
        let includePrivate = settings.spotlightIncludesPrivate
        let lockIsOn = appLock.policy.isOn
        let privateAllowed = SpotlightIndexPlan.allowsPrivateTitles(includePrivate: includePrivate, appLockOn: lockIsOn)
        let changes = SpotlightIndexPlan.changes(
            record: currentRecord(), paired: await pairedIDs(), active: await activeLists(),
            includePrivate: includePrivate, appLockOn: lockIsOn, now: clock.now())
        for change in changes {
            // The world may have moved while earlier changes were written: a replace goes ahead only for a domain
            // that is still paired and while the settings it was planned under still hold.
            if case .replace(let domain, _) = change {
                let unchanged =
                    settings.spotlightIncludesPrivate == includePrivate && appLock.policy.isOn == lockIsOn
                guard unchanged else {
                    runAgain = true
                    return
                }
                guard await pairedIDs().contains(domain) else {
                    runAgain = true
                    return
                }
            }
            update { SpotlightIndexPlan.recordingIntent(of: change, includePrivate: privateAllowed, in: $0) }
            let succeeded = await write(change, bound: Self.writeBound)
            update {
                SpotlightIndexPlan.record($0, after: change, succeeded: succeeded, includePrivate: privateAllowed)
            }
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

    // MARK: The record

    private func currentRecord() -> SpotlightIndexRecord {
        SpotlightIndexRecord.decode(store.data(forKey: Self.recordKey))
    }

    /// Reads the stored record afresh, changes it and saves it with no suspension in between, so a pending erasure
    /// another path stored is merged into, never overwritten.
    private func update(_ change: (SpotlightIndexRecord) -> SpotlightIndexRecord) {
        store.set(change(currentRecord()).encoded(), forKey: Self.recordKey)
    }

    // MARK: Turns

    private func acquire() async {
        if isBusy {
            await withCheckedContinuation { waiters.append($0) }
        } else {
            isBusy = true
        }
    }

    private func release() {
        if waiters.isEmpty {
            isBusy = false
        } else {
            waiters.removeFirst().resume()
        }
    }

    // MARK: Writing

    /// Applies one change, giving up after `bound`. A write that passes the bound is cancelled and marked in the
    /// record as unfinished (the next run starts with an erase of everything); the next write waits until the
    /// abandoned one has really ended, so it can never land after a later erase.
    private func write(_ change: SpotlightChange, bound: Duration) async -> Bool {
        if let abandoned {
            await abandoned.value
            self.abandoned = nil
        }
        let writer = self.writer
        let clock = self.clock
        let apply = Task { () -> Bool in
            do {
                try await writer.apply(change)
                return true
            } catch {
                #if canImport(os)
                    Logger(subsystem: "Yak", category: "Spotlight")
                        .error("Index change failed: \(String(describing: type(of: error)), privacy: .public)")
                #endif
                return false
            }
        }
        let finished: Bool? = await withCheckedContinuation { (continuation: CheckedContinuation<Bool?, Never>) in
            let gate = OneShot(continuation)
            let timer = Task {
                do { try await clock.sleep(for: bound) } catch { return }
                gate.finish(nil)
            }
            gate.onFinish = { timer.cancel() }
            Task { gate.finish(await apply.value) }
        }
        guard let finished else {
            apply.cancel()
            abandoned = Task { _ = await apply.value }
            update {
                var record = $0
                record.pendingEraseAll = true
                return record
            }
            return false
        }
        return finished
    }

    // MARK: Instances

    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        // Stored at once, before this call waits for a run in flight, so the erasure is never lost.
        update { SpotlightIndexPlan.recordingRemoval(of: instance.id, in: $0) }
        await acquire()
        defer { release() }
        let change = SpotlightChange.erase(domains: [instance.id])
        let succeeded = await write(change, bound: Self.removalBound)
        update { SpotlightIndexPlan.record($0, after: change, succeeded: succeeded, includePrivate: false) }
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
    private var continuation: CheckedContinuation<Bool?, Never>?
    var onFinish: (@Sendable () -> Void)?

    init(_ continuation: CheckedContinuation<Bool?, Never>) { self.continuation = continuation }

    func finish(_ value: Bool?) {
        let taken = lock.withLock { () -> CheckedContinuation<Bool?, Never>? in
            defer { continuation = nil }
            return continuation
        }
        guard let taken else { return }
        onFinish?()
        taken.resume(returning: value)
    }
}
