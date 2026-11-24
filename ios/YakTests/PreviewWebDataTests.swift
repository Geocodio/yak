import Foundation
import Testing
import WebKit
import YakKit

@testable import Yak

private final class PreviewDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private struct UnreadableTokenStore: TokenStore {
    struct Unreadable: Error {}
    func token(for instance: UUID) throws -> String? { throw Unreadable() }
    func setToken(_ token: String, for instance: UUID) throws {}
    func removeToken(for instance: UUID) throws {}
}

@MainActor @Suite(.serialized) struct PreviewWebDataTests {
    private func known(_ defaults: PreviewDefaults) throws -> [UUID] {
        try defaults.data(forKey: PreviewWebData.knownStoresKey).map { try JSONDecoder().decode([UUID].self, from: $0) }
            ?? []
    }

    @Test func serializedWorkNeverOverlaps() async {
        final class Log: @unchecked Sendable { var events: [String] = [] }
        let log = Log()
        async let first: Void = PreviewWebData.serialized {
            log.events.append("a-start")
            try? await Task.sleep(for: .milliseconds(100))
            log.events.append("a-end")
        }
        async let second: Void = PreviewWebData.serialized {
            log.events.append("b-start")
            log.events.append("b-end")
        }
        _ = await (first, second)
        // The two calls enter in either order; neither runs inside the other.
        let inOrder = [["a-start", "a-end", "b-start", "b-end"], ["b-start", "b-end", "a-start", "a-end"]]
        #expect(inOrder.contains(log.events))
    }

    // MARK: Erase and sweep against a fake store list

    /// A store a web view still holds: WebKit refuses to remove it until the view lets go.
    private struct StoreInUse: Error {}

    private func handOut(_ storeIDs: [UUID], _ defaults: PreviewDefaults) throws {
        defaults.set(try JSONEncoder().encode(storeIDs), forKey: PreviewWebData.knownStoresKey)
    }

    private let noPause: Duration = .milliseconds(1)

    @Test func eraseRemovesBothStoresOfTheInstanceAndLeavesAnotherInstanceAlone() async throws {
        let defaults = PreviewDefaults()
        let instance = UUID()
        let other = UUID()
        let previewID = PreviewDataStoreID.for(instanceID: instance)
        let embedID = PreviewDataStoreID.for(instanceID: instance, purpose: .embed)
        let otherID = PreviewDataStoreID.for(instanceID: other)
        #expect(previewID != embedID)
        #expect(previewID != otherID)
        try handOut([previewID, embedID, otherID], defaults)
        let stores = StaleStores(listed: [previewID, embedID, otherID], staysFor: 0)
        let isErased = await PreviewWebData.erase(
            instanceID: instance, defaults: defaults, remove: stores.removal, listStores: stores.listing,
            pause: noPause)
        #expect(isErased)
        #expect(stores.listed == [otherID])
        #expect(defaults.data(forKey: PreviewWebData.pendingKey) == nil)
        // The records stay until a sweep finds the stores unlisted.
        #expect(Set(try known(defaults)) == [previewID, embedID, otherID])
    }

    @Test func aPendingInstanceErasesItsEmbedStoreEvenWhenTheKnownRecordIsUnreadable() async throws {
        let defaults = PreviewDefaults()
        let instance = UUID()
        let embedID = PreviewDataStoreID.for(instanceID: instance, purpose: .embed)
        defaults.set(Data("not json".utf8), forKey: PreviewWebData.knownStoresKey)
        defaults.set(try JSONEncoder().encode([instance]), forKey: PreviewWebData.pendingKey)
        let stores = StaleStores(listed: [embedID], staysFor: 0)
        let isErased = await PreviewWebData.erase(
            instanceID: instance, defaults: defaults, remove: stores.removal, listStores: stores.listing,
            pause: noPause)
        #expect(isErased)
        #expect(stores.listed.isEmpty)
        #expect(defaults.data(forKey: PreviewWebData.pendingKey) == nil)
    }

    @Test func aStoreInUseBecomesPendingAndPrepareFinishesIt() async throws {
        let defaults = PreviewDefaults()
        let instance = UUID()
        let storeID = PreviewDataStoreID.for(instanceID: instance)
        try handOut([storeID], defaults)
        let stores = StaleStores(listed: [storeID], staysFor: 0)
        stores.held = [storeID]
        let isErased = await PreviewWebData.erase(
            instanceID: instance, defaults: defaults, remove: stores.removal, listStores: stores.listing,
            pause: noPause)
        #expect(!isErased)
        #expect(stores.listed == [storeID])
        let pending = try JSONDecoder().decode(
            [UUID].self, from: try #require(defaults.data(forKey: PreviewWebData.pendingKey)))
        #expect(pending == [instance])
        // The view lets go of the store: the next preparation removes it and the instance is no longer pending.
        stores.held = []
        #expect(
            await PreviewWebData.prepare(
                instanceID: instance, defaults: defaults, remove: stores.removal, listStores: stores.listing,
                pause: noPause))
        #expect(stores.listed.isEmpty)
        #expect(defaults.data(forKey: PreviewWebData.pendingKey) == nil)
    }

    @Test func theSweepErasesGoneInstancesAndLeavesOtherStores() async throws {
        let defaults = PreviewDefaults()
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(defaults: PreviewDefaults(), tokens: tokens, now: { Date() })
        let kept = try await instances.add(
            url: URL(string: "https://yak.example.test")!, name: "Example Yak", userName: "Test person", token: "t")
        let gone = UUID()
        let needsPairing = try await instances.add(
            url: URL(string: "https://pairing.example.test")!, name: "Example Yak", userName: "Test person", token: "t")
        try tokens.removeToken(for: needsPairing.id)
        let keptID = PreviewDataStoreID.for(instanceID: kept.id)
        let goneID = PreviewDataStoreID.for(instanceID: gone)
        let goneEmbedID = PreviewDataStoreID.for(instanceID: gone, purpose: .embed)
        let pairingID = PreviewDataStoreID.for(instanceID: needsPairing.id)
        let foreignID = UUID()
        try handOut([keptID, goneID, goneEmbedID, pairingID], defaults)
        let stores = StaleStores(listed: [keptID, goneID, goneEmbedID, pairingID, foreignID], staysFor: 1)
        await PreviewWebData.sweep(
            instances: instances, defaults: defaults, listStores: stores.listing, remove: stores.removal,
            pause: noPause)
        #expect(stores.listed == [keptID, foreignID])
        #expect(stores.removals[keptID] == nil)
        #expect(stores.removals[foreignID] == nil)
        // A store removed just now stays recorded until a sweep finds it unlisted.
        await PreviewWebData.sweep(
            instances: instances, defaults: defaults, listStores: stores.listing, remove: stores.removal,
            pause: noPause)
        #expect(try known(defaults) == [keptID])
    }

    @Test func theSweepKeepsTheStoreOfAnInstanceWhoseTokenIsUnreadable() async throws {
        let defaults = PreviewDefaults()
        let instances = InstanceStore(
            defaults: PreviewDefaults(), tokens: UnreadableTokenStore(), now: { Date() })
        let added = try await instances.add(
            url: URL(string: "https://yak.example.test")!, name: "Example Yak", userName: "Test person", token: "t")
        #expect(await instances.state(of: added.id) == .tokenUnreadable)
        let storeID = PreviewDataStoreID.for(instanceID: added.id)
        try handOut([storeID], defaults)
        let stores = StaleStores(listed: [storeID], staysFor: 0)
        for _ in 0..<3 {
            await PreviewWebData.sweep(
                instances: instances, defaults: defaults, listStores: stores.listing, remove: stores.removal,
                pause: noPause)
        }
        #expect(stores.listed == [storeID])
        #expect(stores.removals[storeID] == nil)
    }

    @Test func aListingThatTimesOutLeavesTheRecordsAlone() async throws {
        let defaults = PreviewDefaults()
        let instances = InstanceStore(defaults: PreviewDefaults(), tokens: InMemoryTokenStore(), now: { Date() })
        let waiting = UUID()
        let handedOut = UUID()
        _ = PreviewWebData.store(for: handedOut, defaults: defaults)
        defaults.set(try JSONEncoder().encode([waiting]), forKey: PreviewWebData.pendingKey)
        await PreviewWebData.sweep(
            instances: instances, defaults: defaults,
            listStores: {
                try? await Task.sleep(for: .seconds(30))
                return []
            }, limit: .milliseconds(100))
        let pending = try JSONDecoder().decode(
            [UUID].self, from: try #require(defaults.data(forKey: PreviewWebData.pendingKey)))
        #expect(pending == [waiting])
        #expect(try known(defaults) == [PreviewDataStoreID.for(instanceID: handedOut)])
    }

    // MARK: A removal that leaves the store listed

    /// A store list that keeps a store for the first `staysFor` removals of it, as WebKit does after a removal that
    /// reported success.
    @MainActor private final class StaleStores {
        var listed: Set<UUID>
        var removals: [UUID: Int] = [:]
        let staysFor: Int
        /// Stores a web view holds: removing one throws.
        var held: Set<UUID> = []

        init(listed: Set<UUID>, staysFor: Int) {
            self.listed = listed
            self.staysFor = staysFor
        }

        var removal: PreviewWebData.Removal {
            { [self] id in
                removals[id, default: 0] += 1
                if held.contains(id) { throw StoreInUse() }
                if removals[id, default: 0] > staysFor { listed.remove(id) }
            }
        }

        var listing: PreviewWebData.Listing { { [self] in Array(listed) } }
    }

    private func handOut(_ storeID: UUID, _ defaults: PreviewDefaults) throws {
        try handOut([storeID], defaults)
    }

    @Test func aStoreThatStaysListedIsRemovedAgainBeforeTheRecordIsCleared() async throws {
        let defaults = PreviewDefaults()
        let instance = UUID()
        let storeID = PreviewDataStoreID.for(instanceID: instance)
        try handOut(storeID, defaults)
        let stores = StaleStores(listed: [storeID], staysFor: 2)
        let isErased = await PreviewWebData.erase(
            instanceID: instance, defaults: defaults, remove: stores.removal, listStores: stores.listing,
            pause: .milliseconds(1))
        #expect(isErased)
        #expect(stores.removals[storeID] == 3)  // two removals that leave it listed, one that does not
        #expect(stores.listed.isEmpty)
        #expect(defaults.data(forKey: PreviewWebData.pendingKey) == nil)
        // The record stays until a sweep finds the store unlisted.
        #expect(try known(defaults) == [storeID])
        let instances = InstanceStore(defaults: PreviewDefaults(), tokens: InMemoryTokenStore(), now: { Date() })
        await PreviewWebData.sweep(
            instances: instances, defaults: defaults, listStores: stores.listing, remove: stores.removal,
            pause: .milliseconds(1))
        #expect(try known(defaults).isEmpty)
    }

    @Test func aStoreStillListedAfterTheBoundKeepsItsRecordAndTheNextSweepRemovesIt() async throws {
        let defaults = PreviewDefaults()
        let instance = UUID()
        let storeID = PreviewDataStoreID.for(instanceID: instance)
        try handOut(storeID, defaults)
        let stores = StaleStores(listed: [storeID], staysFor: PreviewWebData.removalAttempts)
        let isErased = await PreviewWebData.erase(
            instanceID: instance, defaults: defaults, remove: stores.removal, listStores: stores.listing,
            pause: .milliseconds(1))
        #expect(!isErased)
        #expect(stores.removals[storeID] == PreviewWebData.removalAttempts)
        #expect(try known(defaults) == [storeID])
        let pending = try JSONDecoder().decode(
            [UUID].self, from: try #require(defaults.data(forKey: PreviewWebData.pendingKey)))
        #expect(pending == [instance])
        let instances = InstanceStore(defaults: PreviewDefaults(), tokens: InMemoryTokenStore(), now: { Date() })
        await PreviewWebData.sweep(
            instances: instances, defaults: defaults, listStores: stores.listing, remove: stores.removal,
            pause: .milliseconds(1))
        #expect(stores.listed.isEmpty)
        // The record stays until a sweep finds the store unlisted.
        #expect(try known(defaults) == [storeID])
        await PreviewWebData.sweep(
            instances: instances, defaults: defaults, listStores: stores.listing, remove: stores.removal,
            pause: .milliseconds(1))
        #expect(try known(defaults).isEmpty)
        #expect(defaults.data(forKey: PreviewWebData.pendingKey) == nil)
    }
}

/// The tests that use real WebKit data stores. WebKit's stores are process-global and its network process is shared by
/// every web view in the test process, so these run one at a time and next to no other WebKit work: they sit in
/// the one serialized suite that every test using a web view lives in. Each test uses identifiers of its own, and what
/// they wait for is the real condition, bounded by the shared `TestTimeScale`. The rules for removal (retrying until
/// the store is unlisted, the record kept, the sweep finishing the job, a store in use staying pending) are proved
/// against a fake store list in `PreviewWebDataTests`, because WebKit lists a removed store again at times of its own.
@MainActor @Suite(.serialized) enum WebKitTests {}

extension WebKitTests {
    @MainActor @Suite struct PreviewWebDataRealStoreTests {
        private func cookie(_ name: String) -> HTTPCookie {
            HTTPCookie(properties: [
                .domain: "preview.example.test", .path: "/", .name: name, .value: "1", .secure: "FALSE",
                .expires: Date(timeIntervalSinceNow: 3600),
            ])!
        }

        /// Each call makes the store and lets it go again, because a store that is still held cannot be removed.
        private func setCookie(_ name: String, for instance: UUID, defaults: PreviewDefaults) async {
            await PreviewWebData.store(for: instance, defaults: defaults).httpCookieStore.setCookie(cookie(name))
        }

        /// Sets a cookie in the first store and reads both, while both are alive.
        private func cookiesOfTwoStores(_ first: UUID, _ second: UUID, defaults: PreviewDefaults) async -> (
            [String], [String]
        ) {
            let firstStore = PreviewWebData.store(for: first, defaults: defaults)
            let secondStore = PreviewWebData.store(for: second, defaults: defaults)
            await firstStore.httpCookieStore.setCookie(cookie("only-first"))
            let inFirst = await firstStore.httpCookieStore.allCookies().map(\.name)
            let inSecond = await secondStore.httpCookieStore.allCookies().map(\.name)
            return (inFirst, inSecond)
        }

        /// Sets a cookie in the embed store and reads both stores of the instance, which are let go of afterwards.
        private func cookiesOfEmbedAndPreviewStores(_ instance: UUID, defaults: PreviewDefaults) async -> (
            [String], [String]
        ) {
            let embedStore = PreviewWebData.store(for: instance, purpose: .embed, defaults: defaults)
            let previewStore = PreviewWebData.store(for: instance, defaults: defaults)
            await embedStore.httpCookieStore.setCookie(cookie("session"))
            let inEmbed = await embedStore.httpCookieStore.allCookies().map(\.name)
            let inPreview = await previewStore.httpCookieStore.allCookies().map(\.name)
            return (inEmbed, inPreview)
        }

        /// WebKit lets go of a released store a moment later, so an erase that finds it still in use is repeated until it succeeds.
        private func eraseEventually(_ instance: UUID, defaults: PreviewDefaults) async -> Bool {
            await TestTimeScale.poll { await PreviewWebData.erase(instanceID: instance, defaults: defaults) }
        }

        private func isUnlisted(_ ids: [UUID]) async -> Bool {
            let existing = await WKWebsiteDataStore.allDataStoreIdentifiers
            return ids.allSatisfy { !existing.contains($0) }
        }

        /// True once none of `ids` is listed. It only lists, and runs the launch sweep between lists, as the app does: a
        /// store that WebKit lists again after an erase is for the sweep to remove.
        private func isUnlistedEventually(_ ids: [UUID], defaults: PreviewDefaults) async -> Bool {
            let instances = InstanceStore(defaults: PreviewDefaults(), tokens: InMemoryTokenStore(), now: { Date() })
            return await TestTimeScale.poll {
                await PreviewWebData.sweep(instances: instances, defaults: defaults)
                guard await isUnlisted(ids) else { return false }
                // The sweep that finds the stores gone drops their records.
                await PreviewWebData.sweep(instances: instances, defaults: defaults)
                return await isUnlisted(ids)
            }
        }

        private func known(_ defaults: PreviewDefaults) throws -> [UUID] {
            try defaults.data(forKey: PreviewWebData.knownStoresKey).map {
                try JSONDecoder().decode([UUID].self, from: $0)
            }
                ?? []
        }

        @Test func eachInstanceHasItsOwnStore() async throws {
            let defaults = PreviewDefaults()
            let first = UUID()
            let second = UUID()
            #expect(PreviewDataStoreID.for(instanceID: first) != PreviewDataStoreID.for(instanceID: second))
            let (inFirst, inSecond) = await cookiesOfTwoStores(first, second, defaults: defaults)
            #expect(inFirst == ["only-first"])
            #expect(inSecond.isEmpty)
            #expect(
                Set(try known(defaults))
                    == [PreviewDataStoreID.for(instanceID: first), PreviewDataStoreID.for(instanceID: second)])
            // The stores of this test are removed so they do not pile up; whether WebKit lets go at once is not tested here.
            _ = await PreviewWebData.erase(instanceID: first, defaults: defaults)
            _ = await PreviewWebData.erase(instanceID: second, defaults: defaults)
        }

        @Test func eraseRemovesTheStore() async throws {
            let defaults = PreviewDefaults()
            let instance = UUID()
            await setCookie("saved", for: instance, defaults: defaults)
            #expect(await eraseEventually(instance, defaults: defaults))
            #expect(await isUnlistedEventually([PreviewDataStoreID.for(instanceID: instance)], defaults: defaults))
            #expect(defaults.data(forKey: PreviewWebData.pendingKey) == nil)
            #expect(try known(defaults).isEmpty)
        }

        @Test func theEmbeddedPageHasItsOwnStore() async throws {
            let defaults = PreviewDefaults()
            let instance = UUID()
            let previewID = PreviewDataStoreID.for(instanceID: instance)
            let embedID = PreviewDataStoreID.for(instanceID: instance, purpose: .embed)
            #expect(previewID != embedID)
            let (inEmbed, inPreview) = await cookiesOfEmbedAndPreviewStores(instance, defaults: defaults)
            #expect(inEmbed == ["session"])
            #expect(inPreview.isEmpty)
            #expect(Set(try known(defaults)) == [previewID, embedID])
            _ = await PreviewWebData.erase(instanceID: instance, defaults: defaults)
        }
    }
}
