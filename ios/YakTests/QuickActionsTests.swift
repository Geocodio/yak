import Foundation
import Testing
import UIKit
import YakKit

@testable import Yak

@MainActor
@Suite(.serialized) struct QuickActionsTests {
    private let instance = Instance(
        url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person", lastUsedAt: Date())

    private func snapshot(first: WidgetItem) -> WidgetSnapshot {
        WidgetSnapshot(instanceName: "Example Yak", needsYouCount: 1, needsYou: [first], running: [], updatedAt: Date())
    }

    @Test func itemsCarryTheirLinks() {
        let first = WidgetItem(
            kind: .thread, id: "d0e515ef", title: "Retry failed rows when the import times out", isPrivate: false,
            detail: "Asked you a question", needsYouReason: "question")
        let items = QuickActions.shortcutItems(instance: instance, snapshot: snapshot(first: first))
        #expect(items.map(\.type) == ["open", "new-thread"], "Open sits above New thread")
        #expect(items[0].localizedTitle == "Open Retry failed rows when the import times out")
        #expect(items[0].localizedSubtitle == "Needs you on Example Yak")
        #expect(items[1].localizedTitle == "New thread" && items[1].localizedSubtitle == "Example Yak")
        #expect(
            items[1].userInfo?["link"] as? String == "yak://new?instance=https%3A%2F%2Fyak.example.com")
        #expect((items[0].userInfo?["link"] as? String)?.hasSuffix("id=d0e515ef") == true)
    }

    @Test func noSnapshotOffersOnlyANewThread() {
        #expect(QuickActions.shortcutItems(instance: instance, snapshot: nil).map(\.type) == ["new-thread"])
        #expect(QuickActions.shortcutItems(instance: nil, snapshot: nil).isEmpty)
    }

    @Test func aPrivateItemSaysOpenAPrivateThread() {
        let hidden = WidgetItem(kind: .task, id: "k3m9p2qa", title: nil, isPrivate: true, needsYouReason: "question")
        let items = QuickActions.shortcutItems(instance: instance, snapshot: snapshot(first: hidden))
        #expect(items[0].localizedTitle == "Open a private thread")
    }

    @Test func aLongTitleIsCut() {
        let long = WidgetItem(
            kind: .thread, id: "d0e515ef", title: String(repeating: "x", count: 300), isPrivate: false)
        let items = QuickActions.shortcutItems(instance: instance, snapshot: snapshot(first: long))
        #expect(items[0].localizedTitle.count <= "Open ".count + QuickActionItems.titleLimit)
    }

    @Test func aForgedLinkIsDropped() async {
        QuickActions.reset()
        var received: [URL] = []
        let stream = QuickActions.links()
        let reader = Task { @MainActor in for await link in stream { received.append(link) } }
        for text in [
            "https://example.com", "yak://pair?url=https%3A%2F%2Fyak.example.com&code=abc", "yak://thread?id=d0e515ef",
        ] {
            let forged = UIApplicationShortcutItem(
                type: "open", localizedTitle: "x", localizedSubtitle: nil, icon: nil,
                userInfo: ["link": text as NSString])
            #expect(!QuickActions.deliver(forged))
        }
        await Task.yield()
        reader.cancel()
        #expect(received.isEmpty)
    }

    @Test func aLinkForAnEndedSubscriberWaitsForTheNextOne() async throws {
        QuickActions.reset()
        let first = Task { @MainActor in for await _ in QuickActions.links() {} }
        await Task.yield()
        first.cancel()
        await first.value
        let item = try #require(QuickActions.shortcutItems(instance: instance, snapshot: nil).first)
        #expect(QuickActions.deliver(item))
        var received: [URL] = []
        let reader = Task { @MainActor in for await link in QuickActions.links() { received.append(link) } }
        _ = await TestTimeScale.poll { !received.isEmpty }
        reader.cancel()
        #expect(received.count == 1)
    }

    @Test func aColdLaunchActionIsDeliveredOnce() async throws {
        QuickActions.reset()
        let item = try #require(QuickActions.shortcutItems(instance: instance, snapshot: nil).first)
        #expect(QuickActions.deliver(item))
        var received: [URL] = []
        let stream = QuickActions.links()
        let reader = Task { @MainActor in for await link in stream { received.append(link) } }
        try await Task.sleep(for: .milliseconds(100))
        #expect(received.count == 1)
        let second = QuickActions.links()
        let secondReader = Task { @MainActor in for await _ in second { received.append(URL(string: "yak://dup")!) } }
        try await Task.sleep(for: .milliseconds(100))
        reader.cancel()
        secondReader.cancel()
        #expect(received.count == 1, "a delivered action is not delivered again")
    }

    @Test func aQuickActionReachesTheAppAfterAWindowCloses() async throws {
        QuickActions.reset()
        let (model, _) = makeLinkModel()
        let window = model.makeSceneModel(restored: nil)
        // Every window asks; one loop runs for the app, and closing a window never ends it.
        let controller = lockedController(isOn: false)
        model.followQuickActions(through: controller)
        model.followQuickActions(through: controller)
        model.sceneBecameActive(window)
        model.sceneDisconnected(window)
        let link = "yak://thread?instance=https%3A%2F%2Fyak.example.com&id=a1b2c3d4"
        let item = UIApplicationShortcutItem(
            type: "open", localizedTitle: "x", localizedSubtitle: nil, icon: nil, userInfo: ["link": link as NSString])
        #expect(QuickActions.deliver(item))
        for _ in 0..<200 where model.launchLink == nil { try await Task.sleep(for: .milliseconds(10)) }
        guard case .thread(_, let id, _)? = model.launchLink else {
            Issue.record("the link did not reach the app")
            return
        }
        #expect(id == "a1b2c3d4")
        QuickActions.reset()
    }

    private struct AllowingAuthenticator: DeviceOwnerAuthenticator {
        func capability() async -> CapabilityReading { .unknown }
        func authenticate(reason: String) async -> DeviceOwnerAuthenticationResult { .success }
        func cancel() {}
    }

    private func lockedController(isOn: Bool = true) -> AppLockController {
        let settings = PrivacySettings(store: PushMemoryDefaults())
        settings.appLockOn = isOn
        return AppLockController(settings: settings, authenticator: AllowingAuthenticator(), clock: ManualClock())
    }

    @Test func aQuickActionThatArrivesWhileLockedOpensOnlyAfterTheUnlock() async throws {
        QuickActions.reset()
        let controller = lockedController()
        var opened: [URL] = []
        let router = Task { @MainActor in
            await AppParts.route(QuickActions.links(), through: controller) { opened.append($0) }
        }
        await Task.yield()
        let item = try #require(QuickActions.shortcutItems(instance: instance, snapshot: nil).first)
        #expect(QuickActions.deliver(item))
        try await Task.sleep(for: .milliseconds(100))
        #expect(opened.isEmpty, "nothing opens under the lock")
        await controller.requestUnlock()
        for _ in 0..<100 where opened.isEmpty { await Task.yield() }
        router.cancel()
        #expect(opened.count == 1)
    }

    @Test func anIntentLinkThatArrivesWhileLockedOpensOnlyAfterTheUnlockAndTheNewestWins() async {
        let controller = lockedController()
        var opened: [URL] = []
        let first = URL(string: "yak://thread?instance=https%3A%2F%2Fyak.example.com&id=d0e515ef")!
        let second = URL(string: "yak://new-thread?instance=https%3A%2F%2Fyak.example.com")!
        AppParts.open(first, through: controller) { opened.append($0) }
        AppParts.open(second, through: controller) { opened.append($0) }
        #expect(opened.isEmpty)
        await controller.requestUnlock()
        for _ in 0..<100 where opened.isEmpty { await Task.yield() }
        #expect(opened == [second])
    }

    @Test func aQuickActionThatArrivesWhileLockedOpensInTheAppsWindowOnlyAfterTheUnlock() async throws {
        QuickActions.reset()
        let controller = lockedController()
        let (model, _) = makeLinkModel()
        await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Fyak.example.com&code=a")!)
        await model.confirmLinkPairing()
        let window = model.makeSceneModel(restored: nil)
        window.applyColdStart(.drawer(.mine))
        model.sceneBecameActive(window)
        model.followQuickActions(through: controller)
        let link = "yak://thread?instance=https%3A%2F%2Fyak.example.com&id=a1b2c3d4"
        let item = UIApplicationShortcutItem(
            type: "open", localizedTitle: "x", localizedSubtitle: nil, icon: nil, userInfo: ["link": link as NSString])
        #expect(QuickActions.deliver(item))
        try await Task.sleep(for: .milliseconds(100))
        #expect(window.state.threadID == nil, "nothing opens under the lock")
        await controller.requestUnlock()
        for _ in 0..<200 where window.state.threadID == nil { try await Task.sleep(for: .milliseconds(10)) }
        #expect(window.state.threadID == "a1b2c3d4")
        QuickActions.reset()
    }

    @Test func aLinkThatArrivesWhileLockedOpensInTheWindowThatGotItAfterTheUnlockWithTwoWindows() async throws {
        let controller = lockedController()
        let (model, _) = makeLinkModel()
        await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Fyak.example.com&code=a")!)
        await model.confirmLinkPairing()
        let first = model.makeSceneModel(restored: nil)
        let second = model.makeSceneModel(restored: nil)
        first.applyColdStart(.drawer(.mine))
        second.applyColdStart(.drawer(.mine))
        model.sceneBecameActive(first)
        model.sceneBecameActive(second)
        model.receive(
            url: URL(string: "yak://thread?instance=https%3A%2F%2Fyak.example.com&id=a1b2c3d4")!, in: first,
            through: controller)
        try await Task.sleep(for: .milliseconds(100))
        #expect(first.state.threadID == nil && second.state.threadID == nil)
        await controller.requestUnlock()
        for _ in 0..<200 where first.state.threadID == nil { try await Task.sleep(for: .milliseconds(10)) }
        #expect(first.state.threadID == "a1b2c3d4" && second.state.threadID == nil)
    }

    @Test func aMenuCommandWhileTheLockBlocksInputIsNeverPublished() {
        let commands = SceneCommands(
            availablePanes: [.chat], focusPane: { _ in }, searchThreads: {}, newThread: {}, toggleSidebar: {},
            overview: {}, settings: {}, hasSeveralWindows: true)
        #expect(SceneCommands.published(commands, isBlocked: false, hasSheetOpen: false) != nil)
        #expect(SceneCommands.published(commands, isBlocked: true, hasSheetOpen: false) == nil)
        #expect(SceneCommands.published(commands, isBlocked: false, hasSheetOpen: true) == nil)
    }
}
