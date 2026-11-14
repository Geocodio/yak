import Foundation

public enum InstanceAddress {
    /// Turns what a person typed into the instance's base URL: always https, lowercase host, optional
    /// non-default port, no path. Returns nil when the input is not a usable host.
    public static func normalize(_ input: String) -> URL? {
        var text = input.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, !text.contains(where: \.isWhitespace) else { return nil }
        if let range = text.range(of: "://") {
            guard ["http", "https"].contains(text[..<range.lowerBound].lowercased()) else { return nil }
            text = String(text[range.upperBound...])
        }
        let authority = text.prefix { !"/?#".contains($0) }
        guard !authority.isEmpty, !authority.contains("@") else { return nil }
        var components = URLComponents()
        components.scheme = "https"
        let parts = authority.split(separator: ":", omittingEmptySubsequences: false)
        guard parts.count <= 2, let host = parts.first, isValidHost(host) else { return nil }
        components.host = host.lowercased()
        if parts.count == 2 {
            guard let port = Int(parts[1]), (1...65535).contains(port) else { return nil }
            if port != 443 { components.port = port }
        }
        return components.url
    }

    private static func isValidHost(_ host: Substring) -> Bool {
        host.allSatisfy { $0.isASCII && ($0.isLetter || $0.isNumber || $0 == "-" || $0 == ".") }
            && !host.hasPrefix(".") && !host.hasSuffix(".")
    }
}
