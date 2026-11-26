import Foundation
import Testing
import UIKit
import YakKit

@testable import Yak

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

@MainActor
private final class Reloads {
    var kinds: [String?] = []
    var registrations = 0
}

@MainActor
@Suite struct WidgetReloadSchedulerTests {
    private func list(count: Int, title: String = "Example thread") -> ThreadList {
        let row = ThreadRow(
            id: "d0e515ef", title: title, repo: "example/api", status: .running, needsYouReason: .question,
            needsYouLabel: "Asked you a question", isPrivate: false, isShared: false, isUnattended: false,
            isStarred: false, canDelete: false, group: .today, children: [])
        return ThreadList(
            starred: [], needsYou: count == 0 ? [] : [.thread(row)], threads: [], needsYouCount: count,
            hasAnyThreads: true)
    }

    private func make(hasInstances: Bool = true) async throws -> (
        WidgetReloadScheduler, Reloads, InstanceStore, Instance?
    ) {
        let store = InstanceStore(defaults: MemoryDefaults(), tokens: InMemoryTokenStore(), now: { Date() })
        let instance =
            hasInstances
            ? try await store.add(
                url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person", token: "t")
            : nil
        let reloads = Reloads()
        let scheduler = WidgetReloadScheduler(
            instances: hasInstances ? store : nil,
            registerPending: { _ in await MainActor.run { reloads.registrations += 1 } },
            reload: { reloads.kinds.append($0) })
        return (scheduler, reloads, store, instance)
    }

    @Test func aStarCloseOrDeleteOnThisDeviceReloadsBothKinds() async throws {
        let (scheduler, reloads, _, _) = try await make()
        scheduler.threadsChanged()
        #expect(reloads.kinds == ["NeedsYou", "RunningThreads"])
        let (idle, idleReloads, _, _) = try await make(hasInstances: false)
        idle.threadsChanged()
        #expect(idleReloads.kinds.isEmpty)
    }

    @Test func reloadsOnlyWhenTheSnapshotChanged() async throws {
        let (scheduler, reloads, _, instance) = try await make()
        let example = try #require(instance)
        scheduler.listLoaded(instance: example, list: list(count: 2))
        #expect(reloads.kinds == ["NeedsYou", "RunningThreads"])
        scheduler.listLoaded(instance: example, list: list(count: 2))
        #expect(reloads.kinds.count == 2, "the same list, even with a newer time, reloads nothing")
        scheduler.listLoaded(instance: example, list: list(count: 3))
        #expect(reloads.kinds.count == 4)
    }

    @Test func backgroundReloadsAllOnlyAfterAChange() async throws {
        let (scheduler, reloads, _, instance) = try await make()
        await scheduler.enteredBackground()
        #expect(reloads.kinds.isEmpty, "nothing changed, so no reload")
        #expect(reloads.registrations == 1)
        scheduler.listLoaded(instance: try #require(instance), list: list(count: 2))
        await scheduler.enteredBackground()
        #expect(reloads.kinds == ["NeedsYou", "RunningThreads", nil])
        await scheduler.enteredBackground()
        #expect(reloads.kinds.count == 3, "the change was reloaded once")
        #expect(reloads.registrations == 3)
    }

    @Test func aRemovalClearsTheQuickActionsOfTheRemovedInstance() async throws {
        let (scheduler, _, store, instance) = try await make()
        let example = try #require(instance)
        let item = try #require(QuickActions.shortcutItems(instance: example, snapshot: nil).first)
        UIApplication.shared.shortcutItems = [item]
        let lifecycle = WidgetLifecycle(scheduler: scheduler)
        try await store.remove(example.id)
        await lifecycle.instanceRemoved(example, reason: .unpaired)
        #expect(UIApplication.shared.shortcutItems?.isEmpty == true)
    }

    @Test func aBackgroundRefreshReloadsOnlyWhenACountChanged() async throws {
        let (scheduler, reloads, store, instance) = try await make()
        let example = try #require(instance)
        try await store.setNeedsYouCount(example.id, 2)
        await scheduler.backgroundRefreshFinished()
        #expect(reloads.kinds == ["NeedsYou", "RunningThreads"])
        await scheduler.backgroundRefreshFinished()
        #expect(reloads.kinds.count == 2)
        try await store.setNeedsYouCount(example.id, 3)
        await scheduler.backgroundRefreshFinished()
        #expect(reloads.kinds.count == 4)
    }

    @Test func pairingRegistersAndReloads() async throws {
        let (scheduler, reloads, _, _) = try await make()
        await scheduler.paired()
        #expect(reloads.registrations == 1)
        #expect(reloads.kinds == [nil])
    }

    @Test func aRemovalReloadsAndForgetsTheInstance() async throws {
        let (scheduler, reloads, _, instance) = try await make()
        let example = try #require(instance)
        scheduler.listLoaded(instance: example, list: list(count: 2))
        scheduler.removed(instance: example.id)
        #expect(reloads.kinds.suffix(1) == [nil])
        scheduler.listLoaded(instance: example, list: list(count: 2))
        #expect(reloads.kinds.count == 5, "a removed instance's last list is forgotten")
    }

    @Test func noStorageDoesNothing() async throws {
        let (scheduler, reloads, _, _) = try await make(hasInstances: false)
        let stray = Instance(
            url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person",
            lastUsedAt: Date())
        scheduler.listLoaded(instance: stray, list: list(count: 2))
        await scheduler.enteredBackground()
        await scheduler.backgroundRefreshFinished()
        await scheduler.paired()
        scheduler.removed(instance: stray.id)
        #expect(reloads.kinds.isEmpty && reloads.registrations == 0)
    }
}
