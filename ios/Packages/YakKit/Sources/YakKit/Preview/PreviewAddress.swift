import Foundation

/// The path a person types in the preview's address field, kept on the preview host.
public enum PreviewAddress {
    private static let maximumLength = 2048
    private static let allowedCharacters = Set(
        "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~!$&'()*+,;=:@/?".utf8)

    /// The web's `addressFor`: trimmed; a pasted URL on the preview host gives its path and query; otherwise every leading
    /// "/" and "\" is dropped and one "/" put back. Spaces and other characters outside a URL path are percent-encoded,
    /// control characters removed, the fragment dropped; at most 2048 characters. "" gives "/".
    public static func normalize(_ typed: String, previewHost: String? = nil) -> String {
        var text = typed.trimmingCharacters(in: .whitespacesAndNewlines)
        if let previewHost, let components = URLComponents(string: text),
            let scheme = components.scheme?.lowercased(), scheme == "http" || scheme == "https",
            let url = components.url, PreviewNavigation.authority(of: url) == previewHost.lowercased()
        {
            text = components.percentEncodedPath
            if let query = components.percentEncodedQuery { text += "?" + query }
        }
        if let fragmentStart = text.firstIndex(of: "#") { text = String(text[..<fragmentStart]) }
        text.unicodeScalars.removeAll { $0.properties.generalCategory == .control }
        while let first = text.first, first == "/" || first == "\\" { text.removeFirst() }
        var encoded = "/" + percentEncode(text)
        if encoded.count > maximumLength {
            encoded = String(encoded.prefix(maximumLength))
            for offset in 1...2 where encoded.count >= offset {
                if encoded[encoded.index(encoded.endIndex, offsetBy: -offset)] == "%" {
                    encoded.removeLast(offset)
                    break
                }
            }
        }
        return encoded
    }

    /// previewURL's scheme and authority with the normalized path.
    public static func url(path: String, previewURL: URL) -> URL? {
        guard var components = URLComponents(url: previewURL, resolvingAgainstBaseURL: false) else { return nil }
        let normalized = normalize(path)
        let parts = normalized.split(separator: "?", maxSplits: 1, omittingEmptySubsequences: false)
        components.user = nil
        components.password = nil
        components.fragment = nil
        components.percentEncodedPath = String(parts[0])
        components.percentEncodedQuery = parts.count > 1 ? String(parts[1]) : nil
        return components.url
    }

    /// Path and query; a /_yak/auth URL shows its `to`; nil or empty gives "/".
    public static func display(_ url: URL?) -> String {
        guard let url, let components = URLComponents(url: url, resolvingAgainstBaseURL: false) else { return "/" }
        if components.path == "/_yak/auth" {
            let destination = components.queryItems?.first { $0.name == "to" }?.value
            return normalize(destination ?? "/")
        }
        var text = components.percentEncodedPath
        if let query = components.percentEncodedQuery { text += "?" + query }
        return normalize(text)
    }

    /// Never a ticket: /_yak/auth gives its `to` on the same origin.
    public static func copyable(_ url: URL?) -> URL? {
        guard let url else { return nil }
        return Self.url(path: display(url), previewURL: url)
    }

    private static func percentEncode(_ text: String) -> String {
        var result = ""
        let bytes = Array(text.utf8)
        var index = 0
        while index < bytes.count {
            let byte = bytes[index]
            if byte == UInt8(ascii: "%"), index + 2 < bytes.count, isHex(bytes[index + 1]), isHex(bytes[index + 2]) {
                result += "%" + String(decoding: bytes[(index + 1)...(index + 2)], as: UTF8.self)
                index += 3
                continue
            }
            if allowedCharacters.contains(byte) {
                result.unicodeScalars.append(Unicode.Scalar(byte))
            } else {
                result += "%" + String(byte, radix: 16, uppercase: true).leftPadded(to: 2)
            }
            index += 1
        }
        return result
    }

    private static func isHex(_ byte: UInt8) -> Bool {
        (byte >= 48 && byte <= 57) || (byte >= 65 && byte <= 70) || (byte >= 97 && byte <= 102)
    }
}

extension String {
    fileprivate func leftPadded(to length: Int) -> String {
        count >= length ? self : String(repeating: "0", count: length - count) + self
    }
}
