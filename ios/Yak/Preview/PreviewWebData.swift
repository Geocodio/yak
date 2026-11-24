import Foundation
import WebKit
import YakKit

#if canImport(os)
    import os
#endif

/// The web data stores of each instance: one persistent store per instance and purpose (previews, the embedded thread
/// page), never the default one, erased together when the instance is unpaired or revoked and swept at launch for
/// instances that are gone.
@MainActor
enum PreviewWebData {
    static let eraseLimit: Duration = .seconds(5)
    /// How often a store is removed while WebKit still lists it after a removal that reported success.
    static let removalAttempts = 5
    /// How many checks in a row must find the store unlisted after a removal.
    static let unlistedChecks = 3
    /// The pause before each check whether WebKit still lists a removed store.
    static let removalPause: Duration = .seconds(1)
    /// Instance ids whose store could not be erased yet.
    static let pendingKey = "yak.preview.pendingErase"
    /// Every store id `store(for:defaults:)` handed out and not yet erased; the sweep only looks at these.
    static let knownStoresKey = "yak.preview.knownStores"

    /// The store for an instance's previews, or for its embedded thread page. Its id is recorded before the store is made.
    static func store(
        for instanceID: UUID, purpose: WebDataPurpose = .preview, defaults: any KeyValueStore
    ) -> WKWebsiteDataStore {
        let storeID = PreviewDataStoreID.for(instanceID: instanceID, purpose: purpose)
        update(knownStoresKey, in: defaults) { $0.insert(storeID) }
        return WKWebsiteDataStore(forIdentifier: storeID)
    }

    /// Finishes a pending erase of this instance's store; false when it still cannot.
    static func prepare(
        instanceID: UUID, defaults: any KeyValueStore, remove: @escaping Removal = liveRemoval,
        listStores: @escaping Listing = liveListing, pause: Duration = removalPause
    ) async -> Bool {
        guard (read(pendingKey, in: defaults) as Set<UUID>).contains(instanceID) else { return true }
        return await erase(
            instanceID: instanceID, defaults: defaults, remove: remove, listStores: listStores, pause: pause)
    }

    /// Removes one store by identifier.
    typealias Removal = @MainActor @Sendable (UUID) async throws -> Void
    /// Lists the identifiers of the stores WebKit has.
    typealias Listing = @MainActor @Sendable () async -> [UUID]

    static let liveRemoval: Removal = { try await WKWebsiteDataStore.remove(forIdentifier: $0) }
    static let liveListing: Listing = { await WKWebsiteDataStore.allDataStoreIdentifiers }

    /// Removes the store within `eraseLimit` and checks that WebKit no longer lists it, removing it again while it
    /// does. On a failure, a timeout or a store that is still listed, the instance is recorded as pending and the
    /// store stays in the known record, so the next sweep tries again.
    static func erase(
        instanceID: UUID, defaults: any KeyValueStore, remove: @escaping Removal = liveRemoval,
        listStores: @escaping Listing = liveListing, pause: Duration = removalPause
    ) async -> Bool {
        await serialized {
            await eraseNow(
                instanceID: instanceID, defaults: defaults, remove: remove, listStores: listStores, pause: pause)
        }
    }

    /// Removes `storeID` and returns true only when WebKit has not listed it for `unlistedChecks` checks in a row, one
    /// every `pause`. A removal that reports success is usually followed by WebKit listing the store again a moment
    /// later (its network session writes into it as it closes), so a listed store is removed again, `removalAttempts`
    /// removals at most.
    private static func removeUntilUnlisted(
        _ storeID: UUID, remove: @escaping Removal, listStores: @escaping Listing, pause: Duration
    ) async -> Bool {
        for _ in 1...removalAttempts {
            guard await bounded({ try await remove(storeID) }) else { return false }
            var isListed = false
            for _ in 1...unlistedChecks {
                try? await Task.sleep(for: pause)
                let listed = await bounded(returning: nil, limit: eraseLimit, { Optional(await listStores()) })
                if listed?.contains(storeID) ?? true {
                    isListed = true
                    break
                }
            }
            if !isListed { return true }
        }
        log("A web data store is still listed after it was removed")
        return false
    }

    private static func eraseNow(
        instanceID: UUID, defaults: any KeyValueStore, remove: @escaping Removal, listStores: @escaping Listing,
        pause: Duration
    ) async -> Bool {
        let known = read(knownStoresKey, in: defaults) as Set<UUID>
        let isPending = (read(pendingKey, in: defaults) as Set<UUID>).contains(instanceID)
        // A store never handed out has nothing to erase; a pending instance retries every store it can have, so an
        // unreadable `known` record cannot leave one behind.
        let doomed = PreviewDataStoreID.allStores(for: instanceID).filter { known.contains($0) || isPending }
        guard !doomed.isEmpty else { return true }
        startWebKit()
        var isEveryStoreRemoved = true
        for storeID in doomed {
            let isRemoved = await removeUntilUnlisted(
                storeID, remove: remove, listStores: listStores, pause: pause)
            // A store that was removed can be listed again by a process that still holds it. Its id stays in the
            // known record, and the next sweep drops it once WebKit no longer lists the store, or removes it again.
            if !isRemoved { isEveryStoreRemoved = false }
        }
        if isEveryStoreRemoved {
            update(pendingKey, in: defaults) { $0.remove(instanceID) }
        } else {
            update(pendingKey, in: defaults) { $0.insert(instanceID) }
            log("A web data store could not be erased")
        }
        return isEveryStoreRemoved
    }

    /// At launch: erases the stores this feature handed out whose instance is gone, needs pairing or is pending.
    static func sweep(
        instances: InstanceStore, defaults: any KeyValueStore,
        listStores: @escaping Listing = liveListing, remove: @escaping Removal = liveRemoval,
        limit: Duration = eraseLimit, pause: Duration = removalPause
    ) async {
        await serialized {
            await sweepNow(
                instances: instances, defaults: defaults, listStores: listStores, remove: remove, limit: limit,
                pause: pause)
        }
    }

    private static func sweepNow(
        instances: InstanceStore, defaults: any KeyValueStore,
        listStores: @escaping Listing, remove: @escaping Removal, limit: Duration, pause: Duration
    ) async {
        let all = await instances.all()
        var keep: [UUID] = []
        for instance in all {
            let state = await instances.state(of: instance.id)
            if state == .ready || state == .tokenUnreadable { keep.append(instance.id) }
        }
        let known = Array(read(knownStoresKey, in: defaults) as Set<UUID>)
        let pending = Array(read(pendingKey, in: defaults) as Set<UUID>)
        // Nothing handed out and nothing pending: there is nothing to erase, and WebKit stays untouched.
        guard !known.isEmpty || !pending.isEmpty else { return }
        startWebKit()
        // A listing that times out says nothing about which stores exist, so both records stay as they are.
        guard let existing = await bounded(returning: nil, limit: limit, { Optional(await listStores()) }) else {
            log("The preview data stores could not be listed")
            return
        }
        let doomed = PreviewDataStoreID.toErase(existing: existing, known: known, keep: keep, pending: pending)
        for storeID in doomed {
            _ = await removeUntilUnlisted(storeID, remove: remove, listStores: listStores, pause: pause)
        }
        // A known store that WebKit did not list at the start needs no erase, and a pending instance whose stores were
        // not listed is done. A store removed just now stays recorded: a process that still holds it can make WebKit
        // list it again, and the next sweep removes it again or drops it once it is gone.
        update(knownStoresKey, in: defaults) { $0.formIntersection(existing) }
        update(pendingKey, in: defaults) { ids in
            ids = ids.filter { id in PreviewDataStoreID.allStores(for: id).contains { existing.contains($0) } }
        }
    }

    // MARK: Serial work

    private static var lastWork: Task<Void, Never>?

    /// Runs `work` after every earlier call has finished, so a sweep and an erase never overlap at a suspension point.
    static func serialized<Value: Sendable>(_ work: @escaping @MainActor () async -> Value) async -> Value {
        let previous = lastWork
        let task = Task { @MainActor in
            await previous?.value
            return await work()
        }
        lastWork = Task { _ = await task.value }
        return await task.value
    }

    private static var hasStartedWebKit = false

    /// Listing or removing stores before WebKit has made a web view crashes in WebKit's run loop. One throwaway web view
    /// on the default store starts it; it is not a preview store, so it never holds one during a removal.
    private static func startWebKit() {
        guard !hasStartedWebKit else { return }
        _ = WKWebView(frame: .zero)
        hasStartedWebKit = true
    }

    // MARK: Bounded waits

    @MainActor private final class Gate {
        private var isFinished = false
        func first() -> Bool {
            defer { isFinished = true }
            return !isFinished
        }
    }

    /// True when `work` finished without an error before `eraseLimit`. A call that never returns is left behind.
    private static func bounded(_ work: @escaping @MainActor @Sendable () async throws -> Void) async -> Bool {
        await withCheckedContinuation { (continuation: CheckedContinuation<Bool, Never>) in
            let gate = Gate()
            Task { @MainActor in
                let isDone: Bool
                do {
                    try await work()
                    isDone = true
                } catch {
                    isDone = false
                }
                if gate.first() { continuation.resume(returning: isDone) }
            }
            Task { @MainActor in
                do { try await Task.sleep(for: eraseLimit) } catch { return }
                if gate.first() { continuation.resume(returning: false) }
            }
        }
    }

    private static func bounded<Value: Sendable>(
        returning fallback: Value, limit: Duration = eraseLimit,
        _ work: @escaping @MainActor @Sendable () async -> Value
    ) async -> Value {
        await withCheckedContinuation { (continuation: CheckedContinuation<Value, Never>) in
            let gate = Gate()
            Task { @MainActor in
                let value = await work()
                if gate.first() { continuation.resume(returning: value) }
            }
            Task { @MainActor in
                do { try await Task.sleep(for: limit) } catch { return }
                if gate.first() { continuation.resume(returning: fallback) }
            }
        }
    }

    // MARK: Records

    private static func read<Element: Codable & Hashable>(_ key: String, in defaults: any KeyValueStore) -> Set<
        Element
    > {
        guard let data = defaults.data(forKey: key) else { return [] }
        do {
            return Set(try JSONDecoder().decode([Element].self, from: data))
        } catch {
            log("A preview record could not be read")
            return []
        }
    }

    private static func update<Element: Codable & Hashable>(
        _ key: String, in defaults: any KeyValueStore, _ change: (inout Set<Element>) -> Void
    ) {
        // A list that cannot be read stays as it is, so the ids in it are not lost.
        var records: Set<Element> = []
        if let data = defaults.data(forKey: key) {
            guard let decoded = try? JSONDecoder().decode([Element].self, from: data) else {
                log("A preview record could not be read")
                return
            }
            records = Set(decoded)
        }
        change(&records)
        do {
            defaults.set(records.isEmpty ? nil : try JSONEncoder().encode(Array(records)), forKey: key)
        } catch {
            log("A preview record could not be saved")
        }
    }

    private static func log(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "PreviewWebData").error("\(message, privacy: .public)")
        #endif
    }
}

/// Erases an instance's preview data when the person unpairs it or the server revokes it.
@MainActor
final class PreviewWebDataObserver: InstanceLifecycleObserver {
    let defaults: any KeyValueStore

    init(defaults: any KeyValueStore) { self.defaults = defaults }

    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        _ = await PreviewWebData.erase(instanceID: instance.id, defaults: defaults)
    }

    func sweep(instances: InstanceStore) async {
        await PreviewWebData.sweep(instances: instances, defaults: defaults)
    }
}
