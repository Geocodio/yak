import Foundation

/// Decides which walkthrough video URLs are handed to the player: only the instance's own, over https.
public enum WalkthroughVideoLink {
    public static let maximumLength = 2_048

    /// The URL to hand to the player, or nil. https only, no user or password. The host (lowercased) and the port (a
    /// missing port counts as 443) must equal those of the instance's `url` or `canonicalURL`; the instance address's
    /// scheme is not checked. Plain http to 127.0.0.1 on the instance's port is accepted only with
    /// `allowsLoopbackHTTP`. Strings over `maximumLength` UTF-8 bytes are refused before parsing.
    public static func playable(_ raw: String?, instance: Instance, allowsLoopbackHTTP: Bool) -> URL? {
        guard let raw, !raw.isEmpty, raw.utf8.count <= maximumLength,
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
