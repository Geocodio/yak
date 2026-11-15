import Foundation
import Testing

@testable import YakKit

private func device(name: String = "Test phone", pairedAt: String? = nil) throws -> Me.DeviceInfo {
    var object: [String: Any] = ["id": 1, "name": name, "kind": "phone"]
    if let pairedAt { object["pairedAt"] = pairedAt }
    return try JSONDecoder().decode(Me.DeviceInfo.self, from: JSONSerialization.data(withJSONObject: object))
}

@Suite struct ThisDeviceLineTests {
    @Test func readsTheDate() throws {
        let line = ThisDeviceLine.make(device: try device(pairedAt: "2026-11-12T09:30:00Z"))
        #expect(line.pairedAt == Date(timeIntervalSince1970: 1_794_475_800))
    }

    @Test func fractionalSeconds() throws {
        let line = ThisDeviceLine.make(device: try device(pairedAt: "2026-11-12T09:30:00.250Z"))
        #expect(line.pairedAt == Date(timeIntervalSince1970: 1_794_475_800.25))
    }

    @Test func missingOrBadDate() throws {
        for text in [nil, "", "yesterday", String(repeating: "9", count: 100_000)] {
            #expect(ThisDeviceLine.make(device: try device(pairedAt: text)).pairedAt == nil)
        }
    }

    @Test func theNameNeverMatters() throws {
        let plain = ThisDeviceLine.make(device: try device(pairedAt: "2026-11-12T09:30:00Z"))
        let hostile = ThisDeviceLine.make(
            device: try device(
                name: String(repeating: "x\n", count: 5_000), pairedAt: "2026-11-12T09:30:00Z"))
        let empty = ThisDeviceLine.make(device: try device(name: "", pairedAt: "2026-11-12T09:30:00Z"))
        #expect(plain == hostile)
        #expect(plain == empty)
    }

    @Test func noDevice() {
        #expect(ThisDeviceLine.make(device: nil).pairedAt == nil)
    }
}
