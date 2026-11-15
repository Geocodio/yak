import Foundation

public enum LogLevel: String, Sendable { case debug, info, notice, error, fault }

public struct LogLine: Equatable, Sendable {
    public let date: Date
    public let level: LogLevel
    /// Cleaned, at most 40 characters.
    public let category: String
    /// Redacted, at most 500 characters.
    public let text: String

    public init(date: Date, level: LogLevel, category: String, text: String) {
        self.date = date
        self.level = level
        self.category = category
        self.text = text
    }
}

/// A bounded ring of redacted log lines. Appending is constant time: a fixed buffer with a moving start.
public struct LogRing: Sendable {
    public static let defaultCapacity = 200
    public static let maximumInputLength = 4_096
    public static let maximumLineLength = 500
    static let maximumCategoryLength = 40

    private var buffer: [LogLine?]
    private var start = 0
    private var count = 0

    /// Clamped to 1...1000.
    public init(capacity: Int = LogRing.defaultCapacity) {
        buffer = Array(repeating: nil, count: min(max(capacity, 1), 1_000))
    }

    public mutating func append(date: Date, level: LogLevel, category: String, message: String) {
        let line = LogLine(
            date: date, level: level,
            category: String(
                LogRedactor.flatten(String(category.prefix(Self.maximumInputLength))).prefix(Self.maximumCategoryLength)
            ),
            text: LogRedactor.redact(message))
        if count < buffer.count {
            buffer[(start + count) % buffer.count] = line
            count += 1
        } else {
            buffer[start] = line
            start = (start + 1) % buffer.count
        }
    }

    /// Oldest first.
    public var lines: [LogLine] {
        (0..<count).compactMap { buffer[(start + $0) % buffer.count] }
    }

    /// One line per entry, "HH:mm:ss.SSS level category text", for Copy.
    public var text: String {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.dateFormat = "HH:mm:ss.SSS"
        return lines.map { "\(formatter.string(from: $0.date)) \($0.level.rawValue) \($0.category) \($0.text)" }
            .joined(separator: "\n")
    }
}

public enum LogRedactor {
    // Compiled regexes are immutable after creation, so sharing them across threads is safe.
    nonisolated(unsafe) private static let replacements: [(Regex<AnyRegexOutput>, String)] = {
        func make(_ pattern: String, _ template: String) -> (Regex<AnyRegexOutput>, String) {
            // The patterns are constants; a failure to compile is a programming error caught by the tests.
            (try! Regex(pattern), template)
        }
        return [
            make(#"[{\[].*$"#, "[body removed]"),
            make(#"(?i)\b(Authorization|Set-Cookie|Cookie):.*$"#, "$1: [removed]"),
            make(#"(?i)\bBearer\s+\S+"#, "Bearer [removed]"),
            make(
                #"(?i)\b((?:[A-Za-z0-9_]*(?:token|secret|key)|device_code|user_code|code|password|signature)"?\s*[=:]\s*"?)[^&\s,"]*"#,
                "$1[removed]"),
            make(#"\?\S+"#, "?[removed]"),
            make(#"[A-Za-z0-9+/=_.\-]{24,}"#, "[removed]"),
        ]
    }()

    /// Control, format, separator characters become spaces and whitespace runs collapse.
    static func flatten(_ text: String) -> String {
        var result = String.UnicodeScalarView()
        var lastWasSpace = true
        for scalar in text.unicodeScalars {
            switch scalar.properties.generalCategory {
            case .control, .format, .lineSeparator, .paragraphSeparator, .spaceSeparator:
                if !lastWasSpace { result.append(" ") }
                lastWasSpace = true
            default:
                result.append(scalar)
                lastWasSpace = scalar == " "
            }
        }
        var flattened = String(result)
        if flattened.hasSuffix(" ") { flattened.removeLast() }
        return flattened
    }

    public static func redact(_ message: String) -> String {
        // Step 1 (flattening) also makes every line end the end of the string.
        var text = flatten(String(message.prefix(LogRing.maximumInputLength)))
        for (regex, template) in replacements {
            text = replace(text, regex, template)
        }
        text = removeEmailAddresses(text)
        text = flatten(text)
        if text.count > LogRing.maximumLineLength {
            text = String(text.prefix(LogRing.maximumLineLength - 1)) + "…"
        }
        return text
    }

    /// A plain scan, linear in the input: for each "@", expand over the local-part and domain characters and
    /// replace the run when the domain has a dot followed by a top-level domain of two or more letters.
    static func removeEmailAddresses(_ text: String) -> String {
        let characters = Array(text)
        func isLocal(_ c: Character) -> Bool { (c.isASCII && (c.isLetter || c.isNumber)) || "._%+-".contains(c) }
        func isDomain(_ c: Character) -> Bool { (c.isASCII && (c.isLetter || c.isNumber)) || ".-".contains(c) }
        var result = ""
        var cursor = 0
        var index = 0
        while index < characters.count {
            guard characters[index] == "@" else {
                index += 1
                continue
            }
            var left = index
            while left > cursor, isLocal(characters[left - 1]) { left -= 1 }
            var right = index + 1
            while right < characters.count, isDomain(characters[right]) { right += 1 }
            while right > index + 1, ".-".contains(characters[right - 1]) { right -= 1 }
            let domain = characters[(index + 1)..<right]
            if left < index, hasTopLevelDomain(domain) {
                result += String(characters[cursor..<left]) + "[email]"
                cursor = right
                index = right
            } else {
                index += 1
            }
        }
        return result + String(characters[cursor...])
    }

    private static func hasTopLevelDomain(_ domain: ArraySlice<Character>) -> Bool {
        guard let dot = domain.lastIndex(of: ".") else { return false }
        let tail = domain[(dot + 1)...]
        return tail.count >= 2 && tail.allSatisfy { $0.isASCII && $0.isLetter }
    }

    private static func replace(_ text: String, _ regex: Regex<AnyRegexOutput>, _ template: String) -> String {
        var result = ""
        var cursor = text.startIndex
        for match in text.matches(of: regex) {
            result += text[cursor..<match.range.lowerBound]
            var expanded = template
            if template.contains("$1"), match.output.count > 1, let group = match.output[1].substring {
                expanded = template.replacingOccurrences(of: "$1", with: String(group))
            }
            result += expanded
            cursor = match.range.upperBound
        }
        return result + text[cursor...]
    }
}
