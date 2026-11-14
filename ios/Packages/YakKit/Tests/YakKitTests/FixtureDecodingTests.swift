import Foundation
import Testing

@testable import YakKit

/// Reads the server's contract fixtures. A fixture holds the string "<volatile>" where the value
/// changes per run; typed fields get a stand-in of the right type so the real decoder runs on the real shape.
enum Fixtures {
    static func standIn(for key: String) -> Any {
        let values: [String: Any] = ["current": 1, "min": 1, "port": 443, "isAdmin": false, "kind": "phone"]
        return values[key] ?? "1"
    }

    static func data(_ name: String) throws -> Data {
        // Read through the ios/fixtures symlink: SwiftPM copies a symlinked resource as a dangling link.
        let url = URL(fileURLWithPath: #filePath)
            .deletingLastPathComponent().deletingLastPathComponent().deletingLastPathComponent()
            .deletingLastPathComponent().deletingLastPathComponent()
            .appendingPathComponent("fixtures/\(name).json")
        let object = try JSONSerialization.jsonObject(with: Data(contentsOf: url))
        return try JSONSerialization.data(withJSONObject: replacingVolatile(object, key: ""))
    }

    private static func replacingVolatile(_ value: Any, key: String) -> Any {
        if let dictionary = value as? [String: Any] {
            return dictionary.reduce(into: [String: Any]()) { $0[$1.key] = replacingVolatile($1.value, key: $1.key) }
        }
        if let text = value as? String, text == "<volatile>" { return standIn(for: key) }
        return value
    }
}

@Test(arguments: ["instance", "me", "device-code", "device-token", "device-pair"])
func decodesEveryFixture(name: String) throws {
    let data = try Fixtures.data(name)
    switch name {
    case "instance": #expect(try JSONDecoder().decode(InstanceInfo.self, from: data).yak)
    case "me": #expect(try JSONDecoder().decode(Me.self, from: data).device.kind == "phone")
    case "device-code": #expect(try JSONDecoder().decode(DeviceCode.self, from: data).interval == 5)
    default: #expect(try JSONDecoder().decode(PairingResponse.self, from: data).device.kind == "phone")
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

@Test func endpointsCarryTheDocumentedBodies() throws {
    let start = Endpoint.startDeviceFlow(name: "n", model: "m", osVersion: "27", appVersion: "1")
    #expect(start.path == "device/code" && start.method == .post)
    let startData = try #require(start.body)
    let body = try #require(JSONSerialization.jsonObject(with: startData) as? [String: String])
    #expect(body == ["name": "n", "model": "m", "os_version": "27", "app_version": "1"])
    let pair = Endpoint.pair(code: "c", name: "n", model: "m", osVersion: "27", appVersion: "1")
    let pairData = try #require(pair.body)
    let pairBody = try #require(JSONSerialization.jsonObject(with: pairData) as? [String: String])
    #expect(pairBody["code"] == "c" && pairBody["os_version"] == "27")
    #expect(Endpoint.unpair().method == .delete)
}
