import Foundation

/// Decides which walkthrough video URLs are handed to the player: only the instance's own, over https.
public enum WalkthroughVideoLink {
    public static let maximumLength = 2_048

    /// The URL to hand to the player, or nil. https only, host and port equal to the instance's `url` or
    /// `canonicalURL` (compared normalized), no user or password; plain http to 127.0.0.1 on the instance's port only with
    /// `allowsLoopbackHTTP`. Strings over `maximumLength` are refused before parsing.
    public static func playable(_ raw: String?, instance: Instance, allowsLoopbackHTTP: Bool) -> URL? {
        guard let raw, !raw.isEmpty, raw.count <= maximumLength,
            let components = URLComponents(string: raw),
            components.user == nil, components.password == nil,
            let scheme = components.scheme?.lowercased(),
            let host = components.host?.lowercased(), !host.isEmpty,
            let url = components.url
        else { return nil }

        let addresses = [instance.url, instance.canonicalURL].compactMap { $0 }
        if scheme == "https" {
            let port = components.port ?? 443
            return addresses.contains { address in
                address.host?.lowercased() == host && (address.port ?? 443) == port
            } ? url : nil
        }
        guard scheme == "http", allowsLoopbackHTTP, host == "127.0.0.1", let port = components.port else {
            return nil
        }
        return addresses.contains { $0.host == host && $0.port == port } ? url : nil
    }
}
