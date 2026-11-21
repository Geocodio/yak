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
        #expect(await eraseEventually(first, defaults: defaults))
        #expect(await eraseEventually(second, defaults: defaults))
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

    @Test func theEmbeddedPageHasItsOwnStoreAndEraseRemovesBothStores() async throws {
        let defaults = PreviewDefaults()
        let instance = UUID()
        let previewID = PreviewDataStoreID.for(instanceID: instance)
        let embedID = PreviewDataStoreID.for(instanceID: instance, purpose: .embed)
        #expect(previewID != embedID)
        let (inEmbed, inPreview) = await cookiesOfEmbedAndPreviewStores(instance, defaults: defaults)
        #expect(inEmbed == ["session"])
        #expect(inPreview.isEmpty)
        #expect(Set(try known(defaults)) == [previewID, embedID])
        #expect(await eraseEventually(instance, defaults: defaults))
        #expect(await isUnlistedEventually([embedID, previewID], defaults: defaults))
        #expect(try known(defaults).isEmpty)
    }

    @Test func aPendingInstanceErasesItsEmbedStoreEvenWhenTheKnownRecordIsUnreadable() async throws {
        let defaults = PreviewDefaults()
        let instance = UUID()
        await PreviewWebData.store(for: instance, purpose: .embed, defaults: defaults).httpCookieStore.setCookie(
            cookie("session"))
        defaults.set(Data("not json".utf8), forKey: PreviewWebData.knownStoresKey)
        defaults.set(try JSONEncoder().encode([instance]), forKey: PreviewWebData.pendingKey)
        #expect(await eraseEventually(instance, defaults: defaults))
        #expect(
            await isUnlistedEventually(
                [PreviewDataStoreID.for(instanceID: instance, purpose: .embed)], defaults: defaults))
        #expect(defaults.data(forKey: PreviewWebData.pendingKey) == nil)
    }

    @Test func aStoreInUseBecomesPendingAndPrepareFinishesIt() async throws {
        let defaults = PreviewDefaults()
        let instance = UUID()
        var view: WKWebView? = WKWebView(
            frame: .zero,
            configuration: {
                let configuration = WKWebViewConfiguration()
                configuration.websiteDataStore = PreviewWebData.store(for: instance, defaults: defaults)
                return configuration
            }())
        view?.loadHTMLString("<p>x</p>", baseURL: nil)
        let started = Date()
        let isErased = await PreviewWebData.erase(instanceID: instance, defaults: defaults)
        #expect(Date().timeIntervalSince(started) < 6)
        if !isErased {
            #expect(defaults.data(forKey: PreviewWebData.pendingKey) != nil)
            view = nil
            #expect(await TestTimeScale.poll { await PreviewWebData.prepare(instanceID: instance, defaults: defaults) })
            #expect(defaults.data(forKey: PreviewWebData.pendingKey) == nil)
        }
        withExtendedLifetime(view) {}
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
        await setCookie("pairing", for: needsPairing.id, defaults: defaults)
        await setCookie("kept", for: kept.id, defaults: defaults)
        await setCookie("gone", for: gone, defaults: defaults)
        let foreignID = UUID()
        await WKWebsiteDataStore(forIdentifier: foreignID).httpCookieStore.setCookie(cookie("foreign"))
        // WebKit lets go of a released store a moment later, so a store still in use is swept again until it is gone.
        var existing: [UUID] = []
        let deadline = ContinuousClock.now.advanced(by: TestTimeScale.waitBound * 2)
        repeat {
            await PreviewWebData.sweep(instances: instances, defaults: defaults)
            existing = await WKWebsiteDataStore.allDataStoreIdentifiers
            let isSwept =
                !existing.contains(PreviewDataStoreID.for(instanceID: gone))
                && !existing.contains(PreviewDataStoreID.for(instanceID: needsPairing.id))
            if isSwept { break }
            try await Task.sleep(for: .milliseconds(300))
        } while ContinuousClock.now < deadline
        #expect(existing.contains(PreviewDataStoreID.for(instanceID: kept.id)))
        #expect(!existing.contains(PreviewDataStoreID.for(instanceID: gone)))
        #expect(!existing.contains(PreviewDataStoreID.for(instanceID: needsPairing.id)))
        #expect(existing.contains(foreignID))
        _ = await PreviewWebData.erase(instanceID: kept.id, defaults: defaults)
        try await WKWebsiteDataStore.remove(forIdentifier: foreignID)
    }

    @Test func theSweepKeepsTheStoreOfAnInstanceWhoseTokenIsUnreadable() async throws {
        let defaults = PreviewDefaults()
        let store = InstanceStore(
            defaults: PreviewDefaults(), tokens: UnreadableTokenStore(), now: { Date() })
        let added = try await store.add(
            url: URL(string: "https://yak.example.test")!, name: "Example Yak", userName: "Test person", token: "t")
        #expect(await store.state(of: added.id) == .tokenUnreadable)
        await setCookie("kept", for: added.id, defaults: defaults)
        for _ in 0..<3 { await PreviewWebData.sweep(instances: store, defaults: defaults) }
        let existing = await WKWebsiteDataStore.allDataStoreIdentifiers
        #expect(existing.contains(PreviewDataStoreID.for(instanceID: added.id)))
        _ = await PreviewWebData.erase(instanceID: added.id, defaults: defaults)
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

        init(listed: Set<UUID>, staysFor: Int) {
            self.listed = listed
            self.staysFor = staysFor
        }

        var removal: PreviewWebData.Removal {
            { [self] id in
                removals[id, default: 0] += 1
                if removals[id, default: 0] > staysFor { listed.remove(id) }
            }
        }

        var listing: PreviewWebData.Listing { { [self] in Array(listed) } }
    }

    private func handOut(_ storeID: UUID, _ defaults: PreviewDefaults) throws {
        defaults.set(try JSONEncoder().encode([storeID]), forKey: PreviewWebData.knownStoresKey)
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
