import Foundation
import Testing
import YakKit

@testable import Yak

private let alertPayloadQuestion = """
    {"aps":{"alert":{"title":"A","body":"B"},"thread-id":"d0e515ef"},
     "yak":{"instance":"https://yak.example.com","event":"needs_you","kind":"thread","id":"d0e515ef","needsYouCount":4}}
    """

private func payload(_ json: String) throws -> PushPayload {
    try PushPayload(json: Data(json.utf8))
}

private func delivered(_ identifier: String, instance: String) throws -> DeliveredNotification {
    let json = """
        {"aps":{"alert":{"title":"A","body":"B"}},
         "yak":{"instance":"\(instance)","event":"turn_finished","kind":"thread","id":"d0e515ef"}}
        """
    return DeliveredNotification(identifier: identifier, payload: try payload(json))
}

@MainActor @Suite struct PushControllerTests {
    @Test func unpairingRemovesNotificationsBadgeAndRecord() async throws {
        let rig = try await makePushRig(
            delivered: [
                try delivered("from-home", instance: "https://yak.example.com"),
                try delivered("from-other", instance: "https://other.example.com"),
            ])
        await rig.registrar.setToken(String(repeating: "0123456789abcdef", count: 4), kind: .alert)
        await rig.transport.on("PUT push-tokens/alert")
        #expect(await eventually { rig.files.has("instances/\(rig.home.id.uuidString)/push-registration.json") })
        await rig.controller.instanceRemoved(rig.home)
        #expect(rig.center.identifiers == ["from-other"])
        #expect(rig.center.badge == 5)
        #expect(!rig.files.has("instances/\(rig.home.id.uuidString)/push-registration.json"))
    }

    @Test func unpairingCleansUpPushState() async throws {
        let rig = try await makePushRig(
            delivered: [
                try delivered("from-home", instance: "https://yak.example.com"),
                try delivered("from-other", instance: "https://other.example.com"),
            ])
        rig.activities.add(instance: "https://yak.example.com", thread: "a0000001")
        let otherActivity = rig.activities.add(instance: "https://other.example.com", thread: "a0000002")
        await rig.transport.on("PUT push-tokens/alert")
        await rig.registrar.setToken(String(repeating: "0123456789abcdef", count: 4), kind: .alert)
        #expect(await eventually { rig.files.has("instances/\(rig.home.id.uuidString)/push-registration.json") })
        await rig.controller.instanceRemoved(rig.home)
        #expect(rig.center.identifiers == ["from-other"])
        #expect(rig.activities.current.map(\.activityID) == [otherActivity])
        #expect(rig.center.badge == 5)
        #expect(!rig.files.has("instances/\(rig.home.id.uuidString)/push-registration.json"))
    }

    @Test func aRefreshedAnswerUpdatesTheStatus() async throws {
        let rig = try await makePushRig()
        await rig.controller.start()
        #expect(await eventually { rig.controller.statuses[rig.home.id] != nil })
        let other = try JSONDecoder().decode(
            Me.self, from: Data(meJSON.replacingOccurrences(of: "com.example.yak", with: "com.example.other").utf8))
        try await rig.store.update(rig.home.id, from: other)
        let refreshed = await rig.store.all().first { $0.id == rig.home.id }!
        await rig.controller.instanceRefreshed(refreshed)
        #expect(await eventually { rig.controller.statuses[rig.home.id] == .unavailable(.otherBuild) })
    }

    @Test func theBadgeIsTheSumOfEveryInstance() async throws {
        let rig = try await makePushRig()
        await rig.controller.refreshBadge()
        #expect(rig.center.badge == 5)
        let rule = await rig.controller.foreground(for: try payload(alertPayloadQuestion))
        #expect(rule.showsBanner)
        #expect(rig.center.badge == 7)
    }

    @Test func anOpenThreadIsNotShownAgain() async throws {
        let rig = try await makePushRig()
        let question = try payload(alertPayloadQuestion)
        rig.controller.setVisible(thread: "d0e515ef", instance: rig.home.url, isVisible: true)
        #expect(await rig.controller.foreground(for: question).showsBanner == false)
        rig.controller.setVisible(thread: "d0e515ef", instance: rig.home.url, isVisible: false)
        #expect(await rig.controller.foreground(for: question).showsBanner)
    }

    @Test func registersOnlyWhenAllowed() async throws {
        let denied = try await makePushRig(authorization: .denied)
        await denied.controller.start()
        #expect(denied.permission.registerCount == 0)
        let allowed = try await makePushRig(authorization: .authorized)
        await allowed.transport.on("PUT push-tokens/alert")
        await allowed.controller.start()
        #expect(allowed.permission.registerCount == 1)
        await allowed.controller.didRegister(deviceToken: Data(repeating: 0xab, count: 32))
        #expect(await eventually { await allowed.transport.routes().contains("PUT push-tokens/alert") })
        #expect(allowed.permission.requestCount == 0)
    }

    @Test func aTokenFailureLeavesTheStatusWaiting() async throws {
        let rig = try await makePushRig()
        await rig.controller.start()
        rig.controller.didFailToRegister(URLError(.cannotConnectToHost))
        #expect(await eventually { rig.controller.statuses[rig.home.id] == .waitingForToken })
        #expect(await rig.transport.routes().filter { $0.hasPrefix("PUT push-tokens") }.isEmpty)
    }

    @Test func removalTellsTheServerBeforeForgetting() async throws {
        let rig = try await makePushRig()
        await rig.transport.on("PUT push-tokens/alert")
        await rig.transport.on("PUT push-tokens/live_activity_start")
        await rig.transport.on("DELETE push-tokens/alert")
        await rig.transport.on("DELETE push-tokens/live_activity_start")
        await rig.transport.on("DELETE device")
        await rig.controller.start()
        await rig.registrar.setToken(String(repeating: "0123456789abcdef", count: 4), kind: .alert)
        await rig.registrar.setToken(String(repeating: "fedcba9876543210", count: 4), kind: .liveActivityStart)
        let recordFile = "instances/\(rig.home.id.uuidString)/push-registration.json"
        // Each instance also sends its activity set (a PUT live-activities), so the wait names the token requests and
        // the registered status of both instances rather than a count of PUTs.
        #expect(
            await eventually {
                let tokenRoutes = await rig.transport.routes().filter { $0.hasPrefix("PUT push-tokens") }
                return tokenRoutes.sorted() == [
                    "PUT push-tokens/alert", "PUT push-tokens/alert", "PUT push-tokens/live_activity_start",
                    "PUT push-tokens/live_activity_start",
                ] && rig.controller.statuses[rig.home.id] == .registered
                    && rig.controller.statuses[rig.other.id] == .registered
            })
        await rig.controller.instanceWillBeRemoved(rig.home)
        let sentBeforeUnpair = await rig.transport.recorded.filter { $0.route.hasPrefix("DELETE") }
        #expect(
            sentBeforeUnpair.map(\.route).sorted() == [
                "DELETE push-tokens/alert", "DELETE push-tokens/live_activity_start",
            ])
        #expect(rig.files.has(recordFile))
        await rig.controller.instanceRemoved(rig.home)
        #expect(!rig.files.has(recordFile))
        let touchedOther = await rig.transport.recorded.filter {
            $0.host == "other.example.com" && $0.route.hasPrefix("DELETE")
        }
        #expect(touchedOther.isEmpty)
    }

    @Test func aRevokeCleansUpWithoutTellingTheServer() async throws {
        let rig = try await makePushRig()
        await rig.controller.instanceRemoved(rig.home, reason: .revoked)
        #expect(await rig.transport.routes().filter { $0.hasPrefix("DELETE") }.isEmpty)
    }

    @Test func offlineRemovalStillFinishes() async throws {
        let rig = try await makePushRig()
        await rig.transport.on("PUT push-tokens/alert")
        await rig.controller.start()
        await rig.registrar.setToken(String(repeating: "0123456789abcdef", count: 4), kind: .alert)
        #expect(await eventually { await rig.transport.routes().contains("PUT push-tokens/alert") })
        await rig.transport.setOffline(true)
        await rig.controller.instanceWillBeRemoved(rig.home)
        await rig.controller.instanceRemoved(rig.home)
        #expect(!rig.files.has("instances/\(rig.home.id.uuidString)/push-registration.json"))
    }
}
