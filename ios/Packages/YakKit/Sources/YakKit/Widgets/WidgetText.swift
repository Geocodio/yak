import Foundation

/// Bounds for text and counts that reach a widget from the server.
public enum WidgetText {
    public static let titleLimit = 200
    public static let detailLimit = 120
    public static let instanceNameLimit = 60
    public static let countLimit = 9999

    /// Line breaks, tabs and other control characters become one space, runs of spaces become one, format characters
    /// (direction overrides, zero-width marks) are dropped, the ends are trimmed, the result holds at most
    /// `limit * 4` unicode scalars, and it is cut to `limit` characters with "…" as the last one when it was longer.
    public static func bounded(_ text: String, limit: Int) -> String {
        var flattened = String.UnicodeScalarView()
        let scalarLimit = max(limit, 0) * 4
        var previousWasSpace = true
        for scalar in text.unicodeScalars {
            if flattened.count >= scalarLimit { break }
            switch scalar.properties.generalCategory {
            case .format:
                continue
            case .control, .lineSeparator, .paragraphSeparator, .spaceSeparator:
                if !previousWasSpace { flattened.append(" ") }
                previousWasSpace = true
            default:
                flattened.append(scalar)
                previousWasSpace = false
            }
        }
        var result = String(flattened)
        if result.hasSuffix(" ") { result.removeLast() }
        guard limit > 0 else { return "" }
        guard result.count > limit else { return result }
        var cut = String(result.prefix(limit - 1))
        if cut.hasSuffix(" ") { cut.removeLast() }
        return cut + "…"
    }

    public static func clamped(_ count: Int) -> Int {
        min(max(count, 0), countLimit)
    }

    /// "0" to "99", then "99+".
    public static func countLabel(_ count: Int) -> String {
        let value = clamped(count)
        return value > 99 ? "99+" : String(value)
    }
}
