import Foundation

/// Removes everything private from text that leaves the device in a crash report.
public enum CrashScrubbing {
    /// Input beyond this many UTF-8 bytes is dropped before any pattern runs, so work is bounded.
    public static let maximumInputBytes = 4096
    /// The longest scrubbed text, in characters.
    public static let maximumOutputLength = 512

    /// Context name to the keys that may leave the device.
    public static let allowedContext: [String: Set<String>] = [
        "os": ["name", "version", "build"],
        "device": ["family", "model", "arch", "memory_size", "simulator"],
        "app": ["app_version", "app_build"],
    ]

    /// Tags that may leave the device.
    public static let allowedTags: Set<String> = ["level", "release", "environment"]

    private static let tokenCharacters = Set("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-+/=.")

    private nonisolated(unsafe) static let replacements: [(Regex<AnyRegexOutput>, String)] = [
        (#"[A-Za-z][A-Za-z0-9+.\-]*://\S+"#, "<url>"),
        (#"(?i)\bBearer\s+\S+"#, "Bearer <token>"),
        (#"(?i)\bAuthorization\b[:=]?\s*(?:(?:Basic|Bearer|Token|Digest)\s+)?\S+"#, "Authorization <redacted>"),
        (#"[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}"#, "<email>"),
        (#""[^"]*(?:"|$)"#, "\"<text>\""),
        (#"\u{201C}[^\u{201D}]*(?:\u{201D}|$)"#, "\"<text>\""),
        (#"'[^']*(?:'|$)"#, "\"<text>\""),
        (#"[A-Za-z0-9_\-+/=.]{24,}"#, "<redacted>"),
        (#"\s+"#, " "),
    ].map { pattern, replacement in
        // The patterns are constants; a failure here is a programming error caught by the tests.
        // swiftlint:disable:next force_try
        (try! Regex(pattern), replacement)
    }

    /// Replaces URLs, bearer and Authorization values, email addresses, quoted text and long token-like runs,
    /// collapses whitespace, and cuts to `maximumOutputLength` on a character boundary.
    public static func scrub(_ text: String) -> String {
        var working = boundedInput(text)
        for (pattern, replacement) in replacements {
            working = working.replacing(pattern, with: replacement)
        }
        working = working.trimmingCharacters(in: .whitespaces)
        return String(working.prefix(maximumOutputLength))
    }

    /// The longest prefix of whole characters within `maximumInputBytes`. After a cut the trailing run of
    /// token characters is dropped, since the start of a token can be shorter than the redaction threshold.
    private static func boundedInput(_ text: String) -> String {
        var kept = ""
        var byteCount = 0
        var wasCut = false
        for character in text {
            let width = character.utf8.count
            if byteCount + width > maximumInputBytes {
                wasCut = true
                break
            }
            byteCount += width
            kept.append(character)
        }
        guard wasCut else { return kept }
        while let last = kept.last, tokenCharacters.contains(last) {
            kept.removeLast()
        }
        return kept
    }
}

public enum CrashReportingConfiguration {
    private static let maximumDSNLength = 512

    /// The DSN from the Info.plist value, or nil when it is empty, unexpanded (`$(`), not https,
    /// has no host or no public key, has whitespace, or is longer than 512 characters.
    public static func dsn(from infoValue: String?) -> URL? {
        guard let infoValue else { return nil }
        let value = infoValue.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !value.isEmpty, value.count <= maximumDSNLength, !value.contains("$("),
            !value.contains(where: \.isWhitespace),
            let components = URLComponents(string: value),
            components.scheme == "https",
            let host = components.host, !host.isEmpty,
            let key = components.user, !key.isEmpty,
            let url = components.url
        else { return nil }
        return url
    }
}
