import Foundation
import Testing

@testable import YakKit

/// Reads the server's contract fixtures as they are. A value that changes per run is a typed marker
/// ("<volatile:string>", "<volatile:int>", "<volatile:bool>", "<volatile:null>"); `data` swaps each
/// marker for a value of that type, so the real decoder checks the real shape and types.
enum Fixtures {
    static func data(_ name: String) throws -> Data {
        try JSONSerialization.data(withJSONObject: replacingMarkers(object(name)))
    }

    static func object(_ name: String) throws -> Any {
        try JSONSerialization.jsonObject(with: Data(contentsOf: url(name)))
    }

    /// True when `ios/fixtures/<name>.json` is there.
    static func exists(_ name: String) -> Bool {
        guard let url = try? url(name) else { return false }
        return FileManager.default.fileExists(atPath: url.path)
    }

    private static func url(_ name: String) throws -> URL {
        // The fixtures are read from disk through `#filePath`, not bundled as a SwiftPM resource:
        // `ios/fixtures` is a symlink to the shared fixtures, and SwiftPM copies a symlinked resource
        // as a dangling link, so a bundled copy would be empty. Walk up from this file to the `ios`
        // directory, so a moved file fails with a clear message.
        var directory = URL(fileURLWithPath: #filePath).deletingLastPathComponent()
        while directory.lastPathComponent != "ios" {
            let parent = directory.deletingLastPathComponent()
            try #require(parent != directory, "FixtureDecodingTests.swift must live under the ios directory")
            directory = parent
        }
        return directory.appendingPathComponent("fixtures/\(name).json")
    }

    static func replacingMarkers(_ value: Any) -> Any {
        if let dictionary = value as? [String: Any] { return dictionary.mapValues(replacingMarkers) }
        if let array = value as? [Any] { return array.map(replacingMarkers) }
        switch value as? String {
        case "<volatile:string>": return "volatile"
        case "<volatile:int>": return 1
        case "<volatile:bool>": return false
        case "<volatile:null>": return NSNull()
        default: return value
        }
    }

    /// The fixture with the value at `path` replaced, markers swapped for values of their type.
    static func data(_ name: String, setting path: [String], toJSON replacementJSON: String) throws -> Data {
        let replacement = try JSONSerialization.jsonObject(with: Data(replacementJSON.utf8), options: .fragmentsAllowed)
        func set(_ value: Any, _ path: ArraySlice<String>) -> Any {
            guard let key = path.first, var dictionary = value as? [String: Any] else { return replacement }
            dictionary[key] = set(dictionary[key] ?? NSNull(), path.dropFirst())
            return dictionary
        }
        return try JSONSerialization.data(withJSONObject: replacingMarkers(set(object(name), path[...])))
    }
}

@Test(arguments: ["instance", "me", "device-code", "device-token", "device-pair"])
func decodesEveryFixture(name: String) throws {
    let data = try Fixtures.data(name)
    switch name {
    case "instance": #expect(try JSONDecoder().decode(InstanceInfo.self, from: data).yak)
    case "me":
        let me = try JSONDecoder().decode(Me.self, from: data)
        #expect(me.device.kind == "phone" && me.device.pairedAt == "volatile")
    case "device-code": #expect(try JSONDecoder().decode(DeviceCode.self, from: data).interval == 5)
    default: #expect(try JSONDecoder().decode(PairingResponse.self, from: data).device.kind == "phone")
    }
}

@Test(arguments: [
    "threads", "threads-children", "thread", "thread-question", "thread-new", "thread-store",
    "thread-message-sent", "thread-message-queued", "thread-message-clear",
])
func decodesEveryThreadFixture(name: String) throws {
    try #require(Fixtures.exists(name), "The server fixture \(name) is missing.")
    let data = try Fixtures.data(name)
    switch name {
    case "threads": _ = try JSONDecoder().decode(ThreadList.self, from: data)
    case "threads-children":
        let list = try JSONDecoder().decode(ThreadList.self, from: data)
        #expect(list.threads.first?.children.isEmpty == false)
    case "thread": #expect(try JSONDecoder().decode(ThreadShow.self, from: data).question == nil)
    case "thread-question":
        #expect(try JSONDecoder().decode(ThreadShow.self, from: data).question?.questions.isEmpty == false)
    case "thread-new": _ = try JSONDecoder().decode(NewThreadOptions.self, from: data)
    case "thread-store": _ = try JSONDecoder().decode(ThreadCreated.self, from: data)
    case "thread-message-sent": #expect(try JSONDecoder().decode(MessageSendResult.self, from: data).status == .sent)
    case "thread-message-queued":
        #expect(try JSONDecoder().decode(MessageSendResult.self, from: data).status == .queued)
    default:
        let clear = try JSONDecoder().decode(MessageSendResult.self, from: data)
        #expect(clear.status == .sent && clear.uuid == nil && clear.threadId != nil)
    }
}

@Test(arguments: ["thread-events", "thread-event", "broadcasting-auth-private", "broadcasting-auth-presence"])
func decodesEveryEventFixture(name: String) throws {
    try #require(Fixtures.exists(name), "The server fixture \(name) is missing.")
    let data = try Fixtures.data(name)
    switch name {
    case "thread-events":
        #expect(try JSONDecoder().decode(EventsPage.self, from: data).events.allSatisfy { $0.payload != .unknown })
    case "thread-event": #expect(try JSONDecoder().decode(ThreadEvent.self, from: data).payload != .unknown)
    case "broadcasting-auth-private":
        #expect(try JSONDecoder().decode(ChannelAuthorization.self, from: data).channelData == nil)
    default: #expect(try JSONDecoder().decode(ChannelAuthorization.self, from: data).channelData != nil)
    }
}

@Test(arguments: ["simple-turn", "steer", "question", "failed-turn", "stream-reset", "pointer"])
func decodesEveryServerEventList(name: String) throws {
    try #require(Fixtures.exists("events/\(name)"), "The server fixture events/\(name) is missing.")
    let list = try EventList.loadFixture("events/\(name)")
    let sequences = list.broadcasts.map { item -> Int in
        switch item {
        case .event(let event): return event.sequence
        case .pointer(let sequence): return sequence
        }
    }
    #expect(sequences == Array(1...(sequences.count + 1)).dropLast())
    #expect(list.events.allSatisfy { $0.payload != .unknown })
    // A catch-up served from the live window still carries deltas and stream resets; one served from the
    // database does not. Either way, replaying it must leave the same stored events as the socket did.
    var fromSocket = ThreadEventState()
    fromSocket.apply(list.events)
    var fromCatchUp = ThreadEventState()
    fromCatchUp.apply(list.catchUp.events, latestSequence: list.catchUp.latestSequence)
    #expect(fromCatchUp.storedEvents.map(\.sequence) == fromSocket.storedEvents.map(\.sequence))
    #expect(fromCatchUp.lastSequence == list.catchUp.latestSequence)
    let pointerCount = list.broadcasts.filter { if case .pointer = $0 { true } else { false } }.count
    #expect(list.pointers.count == pointerCount)
    switch name {
    case "stream-reset": #expect(list.events.contains { $0.payload == .streamReset })
    case "question":
        let last = list.events.suffix(2).map { $0.payload }
        #expect(last == [.question(QuestionChange(open: true)), .question(QuestionChange(open: false))])
    case "pointer": #expect(pointerCount == 1)
    default: break
    }
}

/// A wrongly typed field must fail the decode; this is what keeps the fixtures honest.
@Test(arguments: [
    ("me", ["needsYouCount"], #""0""#),
    ("me", ["realtime", "port"], #""443""#),
    ("me", ["user", "isAdmin"], #""false""#),
    ("me", ["instance", "name"], "7"),
    ("instance", ["yak"], #""yes""#),
    ("instance", ["api", "current"], #""1""#),
    ("device-code", ["expires_in"], #""600""#),
    ("device-pair", ["token"], "5"),
])
func rejectsAWronglyTypedField(name: String, path: [String], replacement: String) throws {
    let data = try Fixtures.data(name, setting: path, toJSON: replacement)
    #expect(throws: DecodingError.self) {
        switch name {
        case "instance": _ = try JSONDecoder().decode(InstanceInfo.self, from: data)
        case "me": _ = try JSONDecoder().decode(Me.self, from: data)
        case "device-code": _ = try JSONDecoder().decode(DeviceCode.self, from: data)
        default: _ = try JSONDecoder().decode(PairingResponse.self, from: data)
        }
    }
}

@Test func ignoresUnknownKeysAndKinds() throws {
    let meData = try Fixtures.data("me")
    var object = try #require(JSONSerialization.jsonObject(with: meData) as? [String: Any])
    object["somethingNew"] = ["a": 1]
    var device = try #require(object["device"] as? [String: Any])
    device["kind"] = "watch"
    object["device"] = device
    let me = try JSONDecoder().decode(Me.self, from: JSONSerialization.data(withJSONObject: object))
    #expect(me.device.kind == "watch")
}

@Test func lenientIDAcceptsNumbersAndStrings() throws {
    struct Holder: Decodable { let id: LenientID }
    #expect(try JSONDecoder().decode(Holder.self, from: Data(#"{"id":7}"#.utf8)).id.value == "7")
    #expect(try JSONDecoder().decode(Holder.self, from: Data(#"{"id":"<volatile>"}"#.utf8)).id.value == "<volatile>")
}

private func jsonBody<Response>(_ endpoint: Endpoint<Response>) throws -> [String: String] {
    let body = try #require(endpoint.body)
    let data = try JSONEncoder().encode(body)
    return try #require(JSONSerialization.jsonObject(with: data) as? [String: String])
}

@Test func endpointsCarryTheDocumentedBodies() throws {
    let start = Endpoint.startDeviceFlow(name: "n", model: "m", osVersion: "27", appVersion: "1")
    #expect(start.path == "device/code" && start.method == .post)
    #expect(try jsonBody(start) == ["name": "n", "model": "m", "os_version": "27", "app_version": "1"])

    let poll = Endpoint.pollDeviceToken(deviceCode: "d", requestID: "r")
    #expect(poll.path == "device/token" && poll.method == .post)
    #expect(try jsonBody(poll) == ["device_code": "d", "request_id": "r"])

    let pair = Endpoint.pair(code: "c", name: "n", model: "m", osVersion: "27", appVersion: "1")
    #expect(pair.path == "device/pair" && pair.method == .post)
    #expect(
        try jsonBody(pair) == ["code": "c", "name": "n", "model": "m", "os_version": "27", "app_version": "1"])

    let me = Endpoint.me()
    #expect(me.path == "me" && me.method == .get && me.body == nil)
    let unpair = Endpoint.unpair()
    #expect(unpair.path == "device" && unpair.method == .delete)
}

@Test func decodesTheRepositoryAccessFixture() throws {
    let access = try JSONDecoder().decode(RepositoryAccess.self, from: try Fixtures.data("thread-repository-access"))
    #expect(access.allowed || access.reason != nil)
}
