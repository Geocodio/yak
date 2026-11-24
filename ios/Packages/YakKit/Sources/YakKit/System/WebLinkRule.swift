import Foundation

/// Decides which server-supplied addresses may open in the in-app browser, which refuses every scheme but https.
public enum WebLinkRule {
    /// The longest address, in UTF-8 bytes, that is looked at.
    public static let maximumLength = 2_048

    /// The URL to open, or nil. https only, with a host and no user or password.
    public static func safeURL(_ text: String?) -> URL? {
        guard let text, !text.isEmpty, text.utf8.count <= maximumLength,
            let components = URLComponents(string: text),
            components.scheme?.lowercased() == "https",
            let host = components.host, !host.isEmpty,
            components.user == nil, components.password == nil
        else { return nil }
        return components.url
    }
}
