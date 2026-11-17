import Foundation
import Testing
import YakKit

@testable import Yak

private func scenarioPayload(_ name: String, instance: String? = nil) throws -> PushPayload {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    let file = directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios/\(name).json")
    var object = try #require(try JSONSerialization.jsonObject(with: Data(contentsOf: file)) as? [String: Any])
    if let instance {
        var yak = object["yak"] as? [String: Any] ?? [:]
        yak["instance"] = instance
        object["yak"] = yak
    }
    return try PushPayload(json: JSONSerialization.data(withJSONObject: object))
}

@MainActor private final class LinkCollector {
    var links: [URL] = []
}

/// Sets the handler after the tap, so a link that waited is delivered to it.
@MainActor
private func firstLink(of controller: PushController) async -> URL? {
    let collector = LinkCollector()
    controller.openLink = { collector.links.append($0) }
    await controller.deliverBufferedLinks()
    return collector.links.first
}

@MainActor @Suite struct PushRoutingTests {
    @Test func aPushForAnotherInstanceSwitchesAndOpensTheThread() async throws {
        let rig = try await makePushRig()
        await rig.controller.didTap(try scenarioPayload("scenario-push-alert-other-instance"))
        let link = try #require(await firstLink(of: rig.controller))
        #expect(link.absoluteString == "yak://thread?instance=https%3A%2F%2Fother.example.com&id=d0e515ef")
        let scene = SceneModel(state: SceneState())
        let parsed = try #require(DeepLink(url: link))
        let outcome = scene.handle(parsed, active: rig.home, instances: [rig.home, rig.other], isCompactWidth: true)
        #expect(outcome == .switchInstance(rig.other))
        #expect(scene.state.threadID == "d0e515ef")
    }

    @Test func aColdTapIsDeliveredOnceTheHandlerIsSet() async throws {
        let rig = try await makePushRig()
        await rig.controller.didTap(try scenarioPayload("scenario-push-alert-question"))
        let collector = LinkCollector()
        rig.controller.openLink = { collector.links.append($0) }
        await rig.controller.deliverBufferedLinks()
        #expect(
            collector.links.map(\.absoluteString) == [
                "yak://thread?instance=https%3A%2F%2Fyak.example.com&id=d0e515ef"
            ])
        await rig.controller.didTap(try scenarioPayload("scenario-push-alert-question"))
        #expect(collector.links.count == 2)
    }

    @Test func aTapReachesTheAppModelWithNoViewListening() async throws {
        let rig = try await makePushRig()
        let transport = rig.transport
        let app = AppModel(
            instances: rig.store, tokens: rig.tokens,
            makeClient: { url in
                APIClient(
                    baseURL: url, token: nil, transport: transport, appVersion: "1.0.0", systemDescription: "Test")
            },
            push: rig.controller)
        await rig.controller.didTap(try scenarioPayload("scenario-push-alert-question"))
        #expect(
            app.takeLaunchLink()
                == .thread(instance: URL(string: "https://yak.example.com")!, id: "d0e515ef"))
    }

    @Test func startingTwiceStartsOnce() async throws {
        let rig = try await makePushRig()
        await rig.controller.start()
        let registrations = rig.permission.registerCount
        await rig.controller.start()
        #expect(rig.permission.registerCount == registrations)
    }

    @Test func anUnknownInstanceOffersPairing() async throws {
        let rig = try await makePushRig()
        let onlyHome = rig.controller
        let store = rig.store
        try await store.remove(rig.other.id)
        await onlyHome.didTap(try scenarioPayload("scenario-push-alert-other-instance"))
        #expect(onlyHome.pending == .pairing(URL(string: "https://other.example.com")!))
        await onlyHome.didTap(try scenarioPayload("scenario-push-alert-question"))
        let link = await firstLink(of: onlyHome)
        #expect(link?.absoluteString.contains("yak.example.com") == true)
    }

    @Test func anUnknownInstanceWithoutAnIdOffersPairing() async throws {
        let rig = try await makePushRig()
        try await rig.store.remove(rig.other.id)
        for name in ["scenario-push-alert-account", "scenario-push-alert-test"] {
            await rig.controller.didTap(try scenarioPayload(name, instance: "https://other.example.com"))
            #expect(rig.controller.pending == .pairing(URL(string: "https://other.example.com")!))
            rig.controller.clearPending()
        }
    }

    @Test func aPushByTheServersAddressOpensThePairedInstance() async throws {
        let rig = try await makePushRig()
        let alias = try JSONDecoder().decode(
            Me.self,
            from: Data(
                meJSON.replacingOccurrences(
                    of: "\"url\":\"https://yak.example.com\"", with: "\"url\":\"https://alias.example.com\""
                ).utf8))
        try await rig.store.update(rig.home.id, from: alias)
        let home = try #require(await rig.store.all().first { $0.id == rig.home.id })
        #expect(home.canonicalURL == URL(string: "https://alias.example.com"))
        await rig.controller.didTap(
            try scenarioPayload("scenario-push-alert-question", instance: "https://alias.example.com"))
        let link = try #require(await firstLink(of: rig.controller))
        #expect(link.absoluteString == "yak://thread?instance=https%3A%2F%2Fyak.example.com&id=d0e515ef")
        let scene = SceneModel(state: SceneState())
        let outcome = scene.handle(
            try #require(DeepLink(url: link)), active: home, instances: [home], isCompactWidth: true)
        #expect(outcome == .handled)
    }

    @Test func aLinkFromOutsideNamingTheServersOwnAddressIsNotMatched() async throws {
        let rig = try await makePushRig()
        let alias = try JSONDecoder().decode(
            Me.self,
            from: Data(
                meJSON.replacingOccurrences(
                    of: "\"url\":\"https://yak.example.com\"", with: "\"url\":\"https://alias.example.com\""
                ).utf8))
        try await rig.store.update(rig.home.id, from: alias)
        let transport = rig.transport
        let app = AppModel(
            instances: rig.store, tokens: rig.tokens,
            makeClient: { url in
                APIClient(
                    baseURL: url, token: nil, transport: transport, appVersion: "1.0.0", systemDescription: "Test")
            },
            push: rig.controller)
        await app.handle(
            url: URL(string: "yak://thread?instance=https%3A%2F%2Falias.example.com&id=d0e515ef")!)
        // The address is the server's own claim, not one the app verified at pairing: the link stays as it came.
        #expect(app.takeLaunchLink() == .thread(instance: URL(string: "https://alias.example.com")!, id: "d0e515ef"))
        await app.handle(url: URL(string: "yak://thread?instance=https%3A%2F%2Fnobody.example.com&id=d0e515ef")!)
        #expect(app.takeLaunchLink() == .thread(instance: URL(string: "https://nobody.example.com")!, id: "d0e515ef"))
    }

    @Test func anAccountPushOpensTheBrowserOnItsInstance() async throws {
        let rig = try await makePushRig()
        try await rig.store.setActive(rig.home.id)
        await rig.controller.didTap(try scenarioPayload("scenario-push-alert-account"))
        #expect(rig.controller.pending == .webPage(URL(string: "https://yak.example.com/settings/claude-accounts")!))
        #expect(await rig.store.active()?.id == rig.home.id)
        rig.controller.clearPending()
        #expect(rig.controller.pending == nil)
        await rig.controller.didTap(
            try scenarioPayload("scenario-push-alert-account", instance: "https://other.example.com"))
        #expect(
            rig.controller.pending == .webPage(URL(string: "https://other.example.com/settings/claude-accounts")!))
        #expect(await rig.store.active()?.id == rig.other.id)
    }

    @Test func anAccountPathThatLeavesTheInstanceIsRefused() async throws {
        let rig = try await makePushRig()
        let payload = try PushPayload(
            json: Data(
                """
                {"aps":{"alert":{"title":"A","body":"B"}},
                 "yak":{"instance":"https://yak.example.com","event":"account","kind":"account","path":"//evil.example.net/x"}}
                """.utf8))
        await rig.controller.didTap(payload)
        #expect(rig.controller.pending == nil)
    }

    @Test func aTaskPushOpensItsPage() async throws {
        let rig = try await makePushRig()
        let payload = try PushPayload(
            json: Data(
                """
                {"aps":{"alert":{"title":"A","body":"B"}},
                 "yak":{"instance":"https://yak.example.com","event":"pull_request","kind":"task","id":"k3m9p2qa"}}
                """.utf8))
        await rig.controller.didTap(payload)
        let link = try #require(await firstLink(of: rig.controller))
        let scene = SceneModel(state: SceneState())
        let outcome = scene.handle(
            try #require(DeepLink(url: link)), active: rig.home, instances: [rig.home], isCompactWidth: true)
        #expect(outcome == .openTaskPage(URL(string: "https://yak.example.com/tasks/k3m9p2qa")!))
    }

    @Test func aTaskPushForAnotherPairedInstanceSwitchesToItAndOpensTheTask() async throws {
        let rig = try await makePushRig()
        let payload = try PushPayload(
            json: Data(
                """
                {"aps":{"alert":{"title":"A","body":"B"}},
                 "yak":{"instance":"https://other.example.com","event":"pull_request","kind":"task","id":"k3m9p2qa"}}
                """.utf8))
        await rig.controller.didTap(payload)
        let link = try #require(await firstLink(of: rig.controller))
        let scene = SceneModel(state: SceneState())
        let outcome = scene.handle(
            try #require(DeepLink(url: link)), active: rig.home, instances: [rig.home, rig.other], isCompactWidth: true)
        #expect(
            outcome
                == .switchInstanceThenOpenTaskPage(rig.other, URL(string: "https://other.example.com/tasks/k3m9p2qa")!))
    }

    @Test func aBadPayloadDoesNothing() async throws {
        let rig = try await makePushRig()
        let payload = try PushPayload(json: Data(#"{"aps":{"alert":"Hello"}}"#.utf8))
        await rig.controller.didTap(payload)
        #expect(rig.controller.pending == nil)
    }
}
