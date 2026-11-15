import Foundation
import Testing

@testable import YakKit

private let moment = Date(timeIntervalSince1970: 1_800_000_000)

private func redacted(_ message: String) -> String { LogRedactor.redact(message) }

struct LogRingTests {
    @Test func theRingKeepsTheNewest200() {
        var ring = LogRing()
        for number in 1...100_000 {
            ring.append(date: moment, level: .info, category: "test", message: "\(number)")
        }
        let lines = ring.lines
        #expect(lines.count == 200)
        #expect(lines.first?.text == "99801")
        #expect(lines.last?.text == "100000")
    }

    @Test func hostileLinesAreRedactedAndBounded() {
        let clock = ContinuousClock()
        let elapsed = clock.measure { runHostileCases() }
        #expect(elapsed < .seconds(1))
    }

    private func runHostileCases() {
        let cases: [(String, [String])] = [
            ("Authorization: Bearer test-token-0123456789", ["test-token-0123456789"]),
            ("bearer\u{00A0}abc.def.ghi0123456789012345678", ["abc.def.ghi"]),
            ("GET https://yak.example.com/api/app/v1/me?token=abc123&x=1", ["abc123", "x=1"]),
            (
                #"{"token":"0123456789abcdef0123456789abcdef","message":"Retry failed rows"}"#,
                ["0123456789abcdef", "Retry failed rows"]
            ),
            ("device_code=XYZW-1234", ["XYZW-1234"]),
            (String(repeating: "pushToken: 0123456789abcdef ", count: 4), ["0123456789abcdef"]),
            ("Contact test.person@example.com", ["test.person@example.com"]),
            (String(repeating: "a%", count: 2_048), []),
            (String(repeating: "a%", count: 2_040) + "@", []),
            (
                "api_key=abcd1234 apiKey=efgh5678 client_secret=ijkl9012 clientSecret=mnop3456",
                ["abcd1234", "efgh5678", "ijkl9012", "mnop3456"]
            ),
            ("Cookie: session=abc123def", ["abc123def"]),
            ("Set-Cookie: sid=zzz999; Path=/", ["zzz999"]),
            (String(repeating: "0123456789abcdefghijklmnop", count: 40_000), ["0123456789abcdefghijklmnop"]),
            (
                "ABCDEFGHIJKLMNOPQRSTUVWX\nabcdefghijklmnopqrstuvwx",
                ["ABCDEFGHIJKLMNOPQRSTUVWX", "abcdefghijklmnopqrstuvwx"]
            ),
            ("ABCDEFGHIJKLMNOPQRSTUVWX\u{202E}abc", ["ABCDEFGHIJKLMNOPQRSTUVWX"]),
            (String(repeating: "x ", count: 2_045) + "SECRETSECRETSECRETSECRETSECRET", ["SECRETSECRET"]),
        ]
        for (input, secrets) in cases {
            let output = redacted(input)
            #expect(output.count <= 500)
            for secret in secrets { #expect(!output.contains(secret), "\(secret) leaked in \(output)") }
            #expect(!output.unicodeScalars.contains { $0.value == 0x202E })
        }
    }

    @Test func harmlessLinesStay() {
        #expect(redacted("Cache write failed: CocoaError") == "Cache write failed: CocoaError")
        #expect(redacted("Request failed: 503") == "Request failed: 503")
    }

    @Test func capacityIsClamped() {
        var tiny = LogRing(capacity: 0)
        var huge = LogRing(capacity: 1_000_000)
        for number in 0..<2_000 {
            tiny.append(date: moment, level: .debug, category: "c", message: "\(number)")
            huge.append(date: moment, level: .debug, category: "c", message: "\(number)")
        }
        #expect(tiny.lines.count == 1)
        #expect(huge.lines.count == 1_000)
    }

    @Test func categoriesAreCleaned() {
        var ring = LogRing()
        ring.append(date: moment, level: .error, category: "a\n" + String(repeating: "b", count: 500), message: "m")
        let category = ring.lines[0].category
        #expect(category.count == 40)
        #expect(!category.contains("\n"))
    }

    @Test func copyTextHasOneLinePerEntry() {
        var ring = LogRing()
        ring.append(date: moment, level: .info, category: "net", message: "first\nsecond")
        ring.append(date: moment, level: .error, category: "net", message: "third")
        let rows = ring.text.split(separator: "\n", omittingEmptySubsequences: false)
        #expect(rows.count == 2)
        #expect(rows[0].hasSuffix("info net first second"))
    }
}
