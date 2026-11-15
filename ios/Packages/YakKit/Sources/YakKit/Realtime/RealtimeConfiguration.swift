import Foundation

/// Where the instance's Reverb server listens, built from `me.realtime`.
public struct RealtimeConfiguration: Equatable, Sendable {
    /// `wss://<host>:<port>/app/<key>?protocol=7&client=yak-app&version=<appVersion>&flash=false`
    public let url: URL

    /// Nil when realtime is nil, the address is unusable, or the scheme is not https (`allowsInsecure`
    /// also accepts http and connects over `ws`).
    public init?(_ realtime: Me.Realtime?, appVersion: String, allowsInsecure: Bool = false) {
        guard let realtime, !realtime.host.isEmpty, !realtime.key.isEmpty,
            (1...65535).contains(realtime.port)
        else { return nil }
        let socketScheme: String
        switch realtime.scheme.lowercased() {
        case "https": socketScheme = "wss"
        case "http" where allowsInsecure: socketScheme = "ws"
        default: return nil
        }
        var components = URLComponents()
        components.scheme = socketScheme
        components.host = realtime.host
        components.port = realtime.port
        components.path = "/app/" + realtime.key
        var allowed = CharacterSet.urlQueryAllowed
        allowed.remove(charactersIn: "&=+#")
        let version = appVersion.addingPercentEncoding(withAllowedCharacters: allowed) ?? ""
        components.percentEncodedQuery = "protocol=7&client=yak-app&version=\(version)&flash=false"
        guard let url = components.url else { return nil }
        self.url = url
    }
}
