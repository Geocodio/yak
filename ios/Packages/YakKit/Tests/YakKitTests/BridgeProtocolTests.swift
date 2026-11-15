import Foundation
import Testing

@testable import YakKit

struct BridgeProtocolTests {
    @Test func parsesTheExitFrame() {
        #expect(BridgeMessage.parse(#"{"type":"exit","code":130}"#) == .exit(code: 130))
        #expect(BridgeMessage.parse(#"{"type":"other"}"#) == .unknown)
        #expect(BridgeMessage.parse("not json") == .unknown)
        #expect(BridgeMessage.parse(#"{"type":"exit","code":1e99}"#) == .unknown)
        #expect(BridgeMessage.parse(#"{"type":"exit","code":-1.5}"#) == .unknown)
        #expect(BridgeMessage.parse(#"{"type":"exit"}"#) == .unknown)
        #expect(BridgeMessage.parse(#"{"type":"exit","code":-9223372036854775808}"#) == .unknown)
        #expect(BridgeMessage.parse(#"{"type":"exit","code":9223372036854775807}"#) == .unknown)
        #expect(BridgeMessage.parse(#"{"type":"exit","code":true}"#) == .unknown)
    }

    @Test func resizeClampsToTheBridgesRange() throws {
        let clamped = BridgeControl.resize(cols: 0, rows: 900)
        let object = try #require(try JSONSerialization.jsonObject(with: Data(clamped.utf8)) as? [String: Any])
        #expect(object.count == 3)
        #expect(object["type"] as? String == "resize")
        #expect(object["cols"] as? Int == 1)
        #expect(object["rows"] as? Int == 500)
        #expect(BridgeControl.resize(cols: 80, rows: 24) == #"{"type":"resize","cols":80,"rows":24}"#)
    }

    @Test(
        arguments: [
            (1000, false, TerminalCloseKind.ended), (4408, true, .ended),
            (1001, false, .lost), (1006, false, .lost), (1011, false, .lost),
            (nil, false, .lost), (4403, false, .denied), (4408, false, .idle),
            (4429, false, .tooMany), (1002, false, .broken), (1003, false, .broken),
            (1009, false, .broken), (4000, false, .broken),
        ] as [(Int?, Bool, TerminalCloseKind)])
    func everyCloseCodeHasOneKind(code: Int?, sawExit: Bool, expected: TerminalCloseKind) {
        #expect(TerminalCloseKind.classify(code: code, sawExit: sawExit) == expected)
    }
}
