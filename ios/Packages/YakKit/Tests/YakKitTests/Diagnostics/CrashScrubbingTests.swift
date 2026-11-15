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
            "A private thread title" \u{201C}Curly title\u{201D} \u{2018}Smart single\u{2019} 'single title' \(base64Run)
            """
        let result = CrashScrubbing.scrub(input)
        for secret in [
            bearerValue, "abc123secret", "token=abc", "auth=x", "person@example.com", "private",
            "Curly", "single", "Smart", base64Run,
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
        #expect(result.hasPrefix("\u{1F468}\u{1F469}\u{1F467}"))
        #expect(!result.unicodeScalars.contains("\u{FFFD}"))
    }

    @Test func inputCutDropsAPartialToken() {
        let token = "ZXCVBNMASDFGHJKLQWERTYUIOPzxcvbnm1234567"
        let input = String(repeating: "ab ", count: 1362) + token
        #expect(input.utf8.count - token.utf8.count == 4086)
        let result = CrashScrubbing.scrub(input)
        #expect(result.hasSuffix("ab"))
        for pair in zip(token, token.dropFirst()) {
            #expect(!result.contains(String(pair.0) + String(pair.1)), "kept \(pair)")
        }
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
            #expect(keys.isDisjoint(with: forbidden), "context \(context)")
        }
        #expect(!CrashScrubbing.allowedTags.isEmpty)
    }

    @Test(
        arguments: [
            #"{"Authorization": "Basic dXNlcjpwYXNz"}"#, #"Authorization: "Bearer dXNlcjpwYXNz""#,
            #"'Authorization'='Token dXNlcjpwYXNz'"#, "Authorization=dXNlcjpwYXNz",
            "authorization: Basic dXNlcjpwYXNz", "Authorization:Bearer dXNlcjpwYXNz",
            "authorization:Basic dXNlcjpwYXNz",
        ])
    func redactsAuthorizationInEveryForm(input: String) {
        let result = CrashScrubbing.scrub(input)
        #expect(!result.contains("dXNlcjpwYXNz"), "leaked in \(result)")
    }

    @Test(
        arguments: [
            ("token=abc123", "<secret>"), ("password=hunter2", "<secret>"), ("api_key: x", "<secret>"),
            ("yak.example.com/api?token=abc", "yak.example.com/api?<secret>"),
            ("client_secret = s3cret end", "<secret> end"),
        ])
    func redactsKeyValuePairs(input: String, expected: String) {
        #expect(CrashScrubbing.scrub(input) == expected)
    }

    @Test func removesInvisibleControlsBeforeMatching() {
        let hostile = "a\u{0}b\u{80}c\u{202E}evil\u{200B}text"
        #expect(CrashScrubbing.scrub(hostile) == "abceviltext")
        #expect(CrashScrubbing.scrub("x\ty\nz") == "x y z")
        let split = "abcdefghijkl\u{200B}mnopqrstuvwxyz0123"
        #expect(CrashScrubbing.scrub(split) == "<redacted>")
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
            "  https://public@sentry.example.invalid/1  ", "https://public@sentry.example.invalid/1\n",
            "https://public@sentry.example.invalid/" + String(repeating: "1", count: 600),
        ] as [String?])
    func refusesEach(infoValue: String?) {
        #expect(CrashReportingConfiguration.dsn(from: infoValue) == nil)
    }
}
