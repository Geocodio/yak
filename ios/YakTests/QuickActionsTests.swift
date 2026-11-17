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
}
