import Foundation
import Testing

@testable import YakKit

private let home = Instance(
    url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test Person", lastUsedAt: .distantPast
)
private let other = Instance(
    url: URL(string: "https://other.example.com")!, name: "Other Yak", userName: "Test Person", lastUsedAt: .distantPast
)

private func payload(_ name: String) throws -> PushPayload { try PushPayload(json: Scenarios.data(name)) }

private func accountPayload(path: String) throws -> PushPayload {
    try PushPayload(
        json: Scenarios.data(
            "scenario-push-alert-account", setting: ["yak", "path"],
            toJSON: "\"\(path.replacingOccurrences(of: "\\", with: "\\\\"))\""))
}

@Test func aThreadPushOpensItsThreadOnItsInstance() throws {
    let payload = try payload("scenario-push-alert-other-instance")
    #expect(PushDestination.make(payload, instances: [home, other]) == .thread(other, id: "d0e515ef"))
    #expect(
        PushDestination.make(payload, instances: [home]) == .unknownInstance(URL(string: "https://other.example.com")!))
}

@Test func anAccountPushOpensItsPathOnThatInstance() throws {
    let payload = try payload("scenario-push-alert-account")
    let url = URL(string: "https://yak.example.com/settings/claude-accounts")!
    #expect(PushDestination.make(payload, instances: [home]) == .webPage(home, url))
    #expect(payload.yak?.id == nil)
}

@Test(arguments: [
    "//evil.example.com/x", "https://evil.example.com", "/\\evil", "/a@evil.example.com", "settings",
    String(repeating: "a", count: 600),
])
func anUnsafePathOpensTheInstanceInstead(path: String) throws {
    #expect(PushDestination.make(try accountPayload(path: path), instances: [home]) == .instance(home))
}

@Test func aTestPushOpensTheInstance() throws {
    #expect(PushDestination.make(try payload("scenario-push-alert-test"), instances: [home]) == .instance(home))
}

@Test(arguments: ["short", "d0e5/5ef", "d0e515efx", ""])
func aBadIdOpensTheInstance(id: String) throws {
    let data = try Scenarios.data("scenario-push-alert-question", setting: ["yak", "id"], toJSON: "\"\(id)\"")
    #expect(PushDestination.make(try PushPayload(json: data), instances: [home]) == .instance(home))
}

@Test func aTaskPushOpensTheTask() throws {
    let data = try Scenarios.data("scenario-push-alert-question", setting: ["yak", "kind"], toJSON: "\"task\"")
    #expect(PushDestination.make(try PushPayload(json: data), instances: [home]) == .task(home, id: "d0e515ef"))
}

@Test func notAYakPush() throws {
    let foreign = try PushPayload(json: Data(#"{"aps":{"alert":"x"}}"#.utf8))
    #expect(foreign.aps.alert?.body == "x")
    #expect(foreign.aps.alert?.title == nil)
    #expect(foreign.yak == nil)
    #expect(PushDestination.make(foreign, instances: [home]) == nil)
    #expect(throws: PushPayloadError.notJSON) { try PushPayload(json: Data("not json".utf8)) }
    #expect(throws: PushPayloadError.notAPush) { try PushPayload(json: Data("{}".utf8)) }
}

@Test func anAlertIsAStringOrAnObject() throws {
    let object = try PushPayload(json: Data(#"{"aps":{"alert":{"title":"T","body":"B"}}}"#.utf8))
    #expect(object.aps.alert == PushPayload.Alert(title: "T", body: "B"))
    let text = try PushPayload(json: Data(#"{"aps":{"alert":"B"}}"#.utf8))
    #expect(text.aps.alert?.title == nil && text.aps.alert?.body == "B")
    #expect(try PushPayload(json: Data(#"{"aps":{}}"#.utf8)).aps.alert == nil)
}

@Test func theApsKeysAreRead() throws {
    let aps = try payload("scenario-push-alert-question").aps
    #expect(aps.threadID == "thread-d0e515ef")
    #expect(aps.interruptionLevel == "time-sensitive")
    #expect(aps.badge == 2)
    #expect(try payload("scenario-push-alert-question").yak?.needsYouCount == 2)
}

@Test func aPushByTheServersAddressFindsThePairedInstance() throws {
    var paired = home
    paired.canonicalURL = URL(string: "https://alias.example.com")
    let data = try Scenarios.data(
        "scenario-push-alert-question", setting: ["yak", "instance"], toJSON: "\"https://alias.example.com\"")
    let payload = try PushPayload(json: data)
    #expect(PushDestination.make(payload, instances: [paired]) == .thread(paired, id: "d0e515ef"))
    let visible: Set = [VisibleThread(instance: paired.url, id: "d0e515ef")]
    #expect(PushForeground.make(payload, visible: visible, instances: [paired]).showsBanner == false)
    let delivered = [DeliveredNotification(identifier: "a", payload: payload)]
    #expect(PushCleanup.identifiers(in: delivered, from: paired) == ["a"])

    let exact = Instance(
        url: URL(string: "https://alias.example.com")!, name: "Alias", userName: "Test Person", lastUsedAt: .distantPast
    )
    #expect(PushDestination.make(payload, instances: [paired, exact]) == .thread(exact, id: "d0e515ef"))
}

@Test func anUnknownInstanceWithoutAnIdStillOffersPairing() throws {
    let expected = PushDestination.unknownInstance(URL(string: "https://other.example.com")!)
    for name in ["scenario-push-alert-account", "scenario-push-alert-test"] {
        let data = try Scenarios.data(name, setting: ["yak", "instance"], toJSON: "\"https://other.example.com\"")
        #expect(PushDestination.make(try PushPayload(json: data), instances: [home]) == expected)
    }
}

@Test func aLinkByTheServersAddressIsRewritten() throws {
    var paired = home
    paired.canonicalURL = URL(string: "https://alias.example.com")
    let link = URL(string: "yak://thread?instance=https%3A%2F%2Falias.example.com&id=d0e515ef")!
    let rewritten = InstanceMatch.pairedLink(link, instances: [paired])
    let items = URLComponents(url: rewritten, resolvingAgainstBaseURL: false)?.queryItems ?? []
    #expect(items.first { $0.name == "instance" }?.value == "https://yak.example.com")
    #expect(items.first { $0.name == "id" }?.value == "d0e515ef")
    #expect(rewritten.scheme == "yak")

    let unknown = URL(string: "yak://thread?instance=https%3A%2F%2Fnobody.example.com&id=d0e515ef")!
    let pair = URL(string: "yak://pair?code=abc")!
    let web = URL(string: "https://alias.example.com/threads/d0e515ef")!
    for unchanged in [unknown, pair, web] {
        #expect(InstanceMatch.pairedLink(unchanged, instances: [paired]) == unchanged)
    }
}

@Test func anOpenThreadGetsNoBanner() throws {
    let payload = try payload("scenario-push-alert-question")
    #expect(
        PushForeground.make(
            payload, visible: [VisibleThread(instance: home.url, id: "d0e515ef")], instances: [home, other])
            == PushForeground(showsBanner: false, playsSound: false))
    #expect(
        PushForeground.make(
            payload, visible: [VisibleThread(instance: other.url, id: "d0e515ef")], instances: [home, other]
        ).showsBanner)
    #expect(PushForeground.make(payload, visible: [], instances: [home, other]).playsSound)
}

@Test func cleanupRemovesOnlyThatInstancesNotifications() throws {
    let delivered = [
        DeliveredNotification(identifier: "question", payload: try payload("scenario-push-alert-question")),
        DeliveredNotification(identifier: "other", payload: try payload("scenario-push-alert-other-instance")),
        DeliveredNotification(identifier: "foreign", payload: nil),
        DeliveredNotification(
            identifier: "plain", payload: try PushPayload(json: Data(#"{"aps":{"alert":"x"}}"#.utf8))),
        DeliveredNotification(identifier: "account", payload: try payload("scenario-push-alert-account")),
    ]
    #expect(PushCleanup.identifiers(in: delivered, from: home) == ["question", "account"])
}

@Test func thePrivateAlertCarriesNoTitle() throws {
    let alert = try payload("scenario-push-alert-private").aps.alert
    #expect(alert?.title == "Private thread")
    #expect(alert?.body == "Open Yak to read it.")
}
