import Foundation

/// One displayable line of a parsed unified diff.
public struct DiffRow: Equatable, Sendable, Identifiable {
    public enum Kind: Equatable, Sendable {
        case hunk(header: String)
        case context
        case added
        case removed
        case noNewlineAtEnd
    }

    /// The row's index in the parsed patch.
    public let id: Int
    public let kind: Kind
    /// Nil for added rows, hunk rows and the no-newline marker.
    public let oldLine: Int?
    /// Nil for removed rows, hunk rows and the no-newline marker.
    public let newLine: Int?
    /// Without the leading "+", "-" or " " and without a trailing "\r".
    public let text: String

    public init(id: Int, kind: Kind, oldLine: Int?, newLine: Int?, text: String) {
        self.id = id
        self.kind = kind
        self.oldLine = oldLine
        self.newLine = newLine
        self.text = text
    }
}

public struct ParsedPatch: Equatable, Sendable {
    public let rows: [DiffRow]
    public let hunkCount: Int
    /// False for a rename or mode change with no hunks.
    public let hasContentChanges: Bool

    public init(rows: [DiffRow], hunkCount: Int, hasContentChanges: Bool) {
        self.rows = rows
        self.hunkCount = hunkCount
        self.hasContentChanges = hasContentChanges
    }
}

public enum UnifiedDiff {
    private static let newline: UInt8 = 0x0A
    private static let carriageReturn: UInt8 = 0x0D
    private static let space: UInt8 = 0x20
    private static let plus: UInt8 = 0x2B
    private static let minus: UInt8 = 0x2D
    private static let comma: UInt8 = 0x2C
    private static let at: UInt8 = 0x40
    private static let backslash: UInt8 = 0x5C
    private static let largestNumber = Int(Int32.max)

    /// Pure and nonisolated: call it off the main actor.
    public static func parse(_ patch: String) -> ParsedPatch {
        var lines = patch.utf8.split(separator: newline, omittingEmptySubsequences: false)
        if lines.last?.isEmpty == true {
            lines.removeLast()
        }

        var rows: [DiffRow] = []
        var hunkCount = 0
        var hasContentChanges = false
        var isInsideHunk = false
        var oldCounter: Int?
        var newCounter: Int?

        for rawLine in lines {
            var line = rawLine
            if line.last == carriageReturn {
                line = line.dropLast()
            }
            let first = line.first
            func text(_ count: Int) -> String {
                String(decoding: line.dropFirst(count), as: UTF8.self)
            }

            if first == at, line.starts(with: [at, at]) {
                isInsideHunk = true
                if let header = parseHunkHeader(line) {
                    hunkCount += 1
                    oldCounter = header.oldStart
                    newCounter = header.newStart
                    rows.append(
                        DiffRow(
                            id: rows.count, kind: .hunk(header: header.section), oldLine: nil, newLine: nil, text: ""))
                } else {
                    oldCounter = nil
                    newCounter = nil
                    rows.append(DiffRow(id: rows.count, kind: .context, oldLine: nil, newLine: nil, text: text(0)))
                }
                continue
            }
            if line.starts(with: "diff --git ".utf8) {
                isInsideHunk = false
                continue
            }
            guard isInsideHunk else { continue }

            switch first {
            case plus:
                hasContentChanges = true
                rows.append(DiffRow(id: rows.count, kind: .added, oldLine: nil, newLine: newCounter, text: text(1)))
                newCounter = newCounter.map { $0 + 1 }
            case minus:
                hasContentChanges = true
                rows.append(DiffRow(id: rows.count, kind: .removed, oldLine: oldCounter, newLine: nil, text: text(1)))
                oldCounter = oldCounter.map { $0 + 1 }
            case backslash:
                rows.append(DiffRow(id: rows.count, kind: .noNewlineAtEnd, oldLine: nil, newLine: nil, text: ""))
            default:
                // A space, an empty line (some tools drop the space) or an unknown prefix.
                let content = first == space ? text(1) : text(0)
                rows.append(
                    DiffRow(id: rows.count, kind: .context, oldLine: oldCounter, newLine: newCounter, text: content))
                oldCounter = oldCounter.map { $0 + 1 }
                newCounter = newCounter.map { $0 + 1 }
            }
        }

        return ParsedPatch(rows: rows, hunkCount: hunkCount, hasContentChanges: hasContentChanges)
    }

    /// Reads `@@ -a[,b] +c[,d] @@ section`. Nil when the header is malformed.
    private static func parseHunkHeader(_ line: Substring.UTF8View) -> (oldStart: Int, newStart: Int, section: String)?
    {
        guard line.starts(with: [at, at, space, minus]) else { return nil }
        var index = line.index(line.startIndex, offsetBy: 4)

        func number() -> Int? {
            var value = 0
            var digitCount = 0
            while index < line.endIndex, let digit = digitValue(line[index]) {
                value = value * 10 + digit
                if value > largestNumber { return nil }
                digitCount += 1
                index = line.index(after: index)
            }
            return digitCount > 0 ? value : nil
        }

        func skipCount() -> Bool {
            guard index < line.endIndex, line[index] == comma else { return true }
            index = line.index(after: index)
            return number() != nil
        }

        guard let oldStart = number(), skipCount() else { return nil }
        guard index < line.endIndex, line[index] == space else { return nil }
        index = line.index(after: index)
        guard index < line.endIndex, line[index] == plus else { return nil }
        index = line.index(after: index)
        guard let newStart = number(), skipCount() else { return nil }
        guard line[index...].starts(with: [space, at, at]) else { return nil }
        index = line.index(index, offsetBy: 3)
        let section = String(decoding: line[index...], as: UTF8.self)
            .trimmingCharacters(in: .whitespaces)
        return (oldStart, newStart, section)
    }

    private static func digitValue(_ byte: UInt8) -> Int? {
        (0x30...0x39).contains(byte) ? Int(byte - 0x30) : nil
    }
}
