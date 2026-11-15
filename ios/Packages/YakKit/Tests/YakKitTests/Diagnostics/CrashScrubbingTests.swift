import Foundation
import Testing

@testable import YakKit

@Suite struct CrashScrubbingTests {
    @Test func replacesEachPrivateKind() {
        let bearerValue = "yak_" + String(repeating: "a1B2", count: 10)
        let base64Run = String(repeating: "Qw3rTy9+", count: 8)
        let input = """
            Bearer \(bearerValue) Authorization: Basic abc123secret \
            https://yak.example.com/api/app/v1/threads?token=abc \
            wss://yak.example.com/app/key?auth=x person@example.com \
            "A private thread title" \u{201C}Curly title\u{201D} 'single title' \(base64Run)
            """
        let result = CrashScrubbing.scrub(input)
        for secret in [
            bearerValue, "abc123secret", "token=abc", "auth=x", "person@example.com", "private",
            "Curly", "single", base64Run,
        ] {
            #expect(!result.contains(secret), "leaked \(secret)")
        }
        for marker in ["<url>", "Bearer <token>", "<email>", "\"<text>\"", "<redacted>"] {
            #expect(result.contains(marker), "missing \(marker)")
        }
    }

    @Test func keepsMachineText() {
        #expect(CrashScrubbing.scrub("Fatal error: Index out of range") == "Fatal error: Index out of range")
        let address = "KERN_INVALID_ADDRESS at 0x0000000000000010"
        #expect(CrashScrubbing.scrub(address) == address)
    }

    @Test func boundsHugeInput() {
        let huge = String(repeating: "word \"quoted\" a@b.example https://x.example/y ", count: 25_000)
        #expect(huge.utf8.count > 1_000_000)
        var result = ""
        let elapsed = ContinuousClock().measure { result = CrashScrubbing.scrub(huge) }
        #expect(elapsed < .seconds(1))
        #expect(result.count <= CrashScrubbing.maximumOutputLength)
    }

    @Test func cutsOnACharacterBoundary() {
        let result = CrashScrubbing.scrub(String(repeating: "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} ", count: 600))
        #expect(result.count <= CrashScrubbing.maximumOutputLength)
        #expect(result.hasPrefix("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"))
        #expect(!result.unicodeScalars.contains("\u{FFFD}"))
    }

    @Test func inputCutDropsAPartialToken() {
        let token = "ZXCVBNMASDFGHJKLQWERTYUIOPzxcvbnm1234567"
        let input = String(repeating: "ab ", count: 1362) + token
        #expect(input.utf8.count - token.utf8.count == 4086)
        let result = CrashScrubbing.scrub(input)
        #expect(!result.contains("ZXCV"))
        #expect(!result.contains("ZXC"))
    }

    @Test func inputCutKeepsMultibyteValid() {
        let input = String(repeating: "a ", count: 2047) + "a" + "\u{E9}" + " tail text"
        #expect(Array(input.utf8)[4095] == 0xC3)
        let result = CrashScrubbing.scrub(input)
        #expect(!result.contains("\u{E9}"))
        #expect(!result.contains("\u{FFFD}"))
        #expect(String(decoding: Array(result.utf8), as: UTF8.self) == result)
    }

    @Test func collapsesNewlines() {
        #expect(CrashScrubbing.scrub("a\n\n\tb") == "a b")
    }

    @Test func emptyStaysEmpty() {
        #expect(CrashScrubbing.scrub("") == "")
    }

    @Test func unterminatedQuoteLeavesNoTitle() {
        let result = CrashScrubbing.scrub("failed for \"Private roadmap thread")
        #expect(!result.contains("roadmap"))
    }

    @Test func allowedContextHasNoNameOrUserKeys() {
        let forbidden: Set<String> = ["name", "user", "email", "ip_address", "username", "device_name"]
        for (context, keys) in CrashScrubbing.allowedContext {
            if context == "os" {
                // The operating system name ("iOS") is public; the others are person-level keys.
                #expect(keys.isDisjoint(with: forbidden.subtracting(["name"])))
            } else {
                #expect(keys.isDisjoint(with: forbidden), "context \(context)")
            }
        }
        #expect(!CrashScrubbing.allowedTags.isEmpty)
    }
}

@Suite struct CrashReportingConfigurationTests {
    @Test func acceptsAPlainDSN() {
        let url = CrashReportingConfiguration.dsn(from: "https://public@sentry.example.invalid/1")
        #expect(url?.host == "sentry.example.invalid")
        #expect(url?.user == "public")
    }

    @Test(
        arguments: [
            nil, "", "   ", "$(YAK_SENTRY_DSN)", "https:/$()/public@sentry.example.invalid/1",
            "http://public@sentry.example.invalid/1", "https://sentry.example.invalid/1",
            "https://public@/1", "https://public@sentry.example.invalid/ 1",
            "https://public@sentry.example.invalid/" + String(repeating: "1", count: 600),
        ] as [String?])
    func refusesEach(infoValue: String?) {
        #expect(CrashReportingConfiguration.dsn(from: infoValue) == nil)
    }
}
