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
    @ObservationIgnored private var abandonedIsReplace = false
    /// Removals asked for while the settings store was unreadable; recorded at the next run that can read it.
    @ObservationIgnored private var removalsWhileUnreadable: [UUID] = []

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
        } while runAgain && !Task.isCancelled
    }

    /// The same run, inside a background task assertion that ends when the run ends or the system takes the time
    /// back, which also cancels the run.
    func reindexInBackground() async {
        await BackgroundWork.run(
            name: "spotlight",
            begin: { name, expiration in
                UIApplication.shared.beginBackgroundTask(withName: name, expirationHandler: expiration)
            },
            end: { UIApplication.shared.endBackgroundTask($0) },
            work: { await self.reindex() })
    }

    private func runOnce() async {
        // The record and both switches live in the settings store: while it is unreadable nothing is planned or
        // written, and the next run (the protected-data notification asks for one) catches up.
        appLock.reloadSettings()
        guard !appLock.storeUnreadable else { return }
        for id in removalsWhileUnreadable { update { SpotlightIndexPlan.recordingRemoval(of: id, in: $0) } }
        removalsWhileUnreadable = []
        state = .updating
        let includePrivate = settings.spotlightIncludesPrivate
        let lockIsOn = appLock.policy.isOn
        let privateAllowed = SpotlightIndexPlan.allowsPrivateTitles(includePrivate: includePrivate, appLockOn: lockIsOn)
        let changes = SpotlightIndexPlan.changes(
            record: currentRecord(), paired: await pairedIDs(), active: await activeLists(),
            includePrivate: includePrivate, appLockOn: lockIsOn, now: clock.now())
        for change in changes {
            if Task.isCancelled {
                state = .failed
                return
            }
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
            if succeeded, change == .eraseAll, abandoned != nil, abandonedIsReplace {
                // An abandoned replace may still land after this erase, so the record must not call the index clean.
                update {
                    var record = $0
                    record.pendingEraseAll = true
                    return record
                }
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
    /// record as unfinished (the next run starts with an erase of everything). Only a replace waits for an abandoned
    /// write to end, and then for at most the write bound: an erasure is idempotent and goes ahead at once, and when
    /// the abandoned write finally ends the erase-everything flag is set again and the index is rebuilt, so a late
    /// landing never survives.
    private func write(_ change: SpotlightChange, bound: Duration) async -> Bool {
        if case .replace = change, let abandoned {
            let ended = await race(
                Task {
                    await abandoned.value
                    return true
                }, within: Self.writeBound)
            guard ended != nil else { return false }
            if self.abandoned == abandoned { self.abandoned = nil }
        }
        let writer = self.writer
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
        guard let finished = await race(apply, within: bound) else {
            apply.cancel()
            let late = Task { _ = await apply.value }
            abandoned = late
            let isReplace: Bool
            if case .replace = change { isReplace = true } else { isReplace = false }
            abandonedIsReplace = isReplace
            update {
                var record = $0
                record.pendingEraseAll = true
                return record
            }
            Task { [weak self] in
                await late.value
                await self?.eraseAfterLateWrite(late, wasReplace: isReplace)
            }
            return false
        }
        return finished
    }

    /// The first of the work and the timer; nil when the timer won.
    private func race(_ work: Task<Bool, Never>, within bound: Duration) async -> Bool? {
        let clock = self.clock
        return await withCheckedContinuation { (continuation: CheckedContinuation<Bool?, Never>) in
            let gate = OneShot(continuation)
            let timer = Task {
                do { try await clock.sleep(for: bound) } catch { return }
                gate.finish(nil)
            }
            gate.whenFinished { timer.cancel() }
            Task { gate.finish(await work.value) }
        }
    }

    /// An abandoned replace has ended, so anything it landed is erased on the next run, which starts now. A late
    /// erase lands nothing new, so it starts no run: otherwise a slow Core Spotlight would erase and rebuild forever.
    private func eraseAfterLateWrite(_ late: Task<Void, Never>, wasReplace: Bool) async {
        if abandoned == late { abandoned = nil }
        guard wasReplace else { return }
        update {
            var record = $0
            record.pendingEraseAll = true
            return record
        }
        await reindex()
    }

    // MARK: Instances

    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        appLock.reloadSettings()
        guard !appLock.storeUnreadable else {
            removalsWhileUnreadable.append(instance.id)
            return
        }
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
    private var onFinish: (@Sendable () -> Void)?

    init(_ continuation: CheckedContinuation<Bool?, Never>) { self.continuation = continuation }

    /// Runs `hook` when the gate finishes, or at once when it already has.
    func whenFinished(_ hook: @escaping @Sendable () -> Void) {
        let alreadyFinished = lock.withLock { () -> Bool in
            if continuation == nil { return true }
            onFinish = hook
            return false
        }
        if alreadyFinished { hook() }
    }

    func finish(_ value: Bool?) {
        let (taken, hook) = lock.withLock { () -> (CheckedContinuation<Bool?, Never>?, (@Sendable () -> Void)?) in
            defer { continuation = nil }
            return (continuation, onFinish)
        }
        guard let taken else { return }
        hook?()
        taken.resume(returning: value)
    }
}

/// Runs work inside a background task that is ended exactly once, by the work or by the expiration handler.
@MainActor enum BackgroundWork {
    @MainActor private final class Assertion {
        var identifier = UIBackgroundTaskIdentifier.invalid
        var isEnded = false
        let end: (UIBackgroundTaskIdentifier) -> Void

        init(end: @escaping (UIBackgroundTaskIdentifier) -> Void) { self.end = end }

        /// Ends the task once it exists; a call before `begin` returned is remembered and applied right after.
        func finish() {
            guard !isEnded else { return }
            guard identifier != .invalid else {
                isEnded = true
                return
            }
            isEnded = true
            end(identifier)
        }
    }

    static func run(
        name: String,
        begin: (String, @escaping @MainActor @Sendable () -> Void) -> UIBackgroundTaskIdentifier,
        end: @escaping (UIBackgroundTaskIdentifier) -> Void,
        work: @escaping @MainActor () async -> Void
    ) async {
        let task = Task { await work() }
        let assertion = Assertion(end: end)
        assertion.identifier = begin(name) {
            task.cancel()
            assertion.finish()
        }
        if assertion.isEnded, assertion.identifier != .invalid { assertion.end(assertion.identifier) }
        await task.value
        assertion.finish()
    }
}
