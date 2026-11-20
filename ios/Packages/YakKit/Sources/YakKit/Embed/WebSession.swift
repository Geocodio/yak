import Foundation

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private struct WebSessionBody: Encodable, Sendable {
    let path: String
}

/// The answer to a web-session mint. `code` is a secret that opens one web session once: it is never logged, never
/// stored (the type is not `Encodable`) and never put in a URL, and every textual form of the value hides it.
public struct WebSession: Decodable, Sendable, Equatable, CustomStringConvertible, CustomDebugStringConvertible,
    CustomReflectable
{
    public let redeemUrl: String
    public let code: String
    public let expiresAt: String

    public init(redeemUrl: String, code: String, expiresAt: String) {
        self.redeemUrl = redeemUrl
        self.code = code
        self.expiresAt = expiresAt
    }

    public var description: String { "WebSession(code: <redacted>)" }
    public var debugDescription: String { description }
    public var customMirror: Mirror { Mirror(self, children: ["code": "<redacted>"]) }
}

extension Endpoint where Response == WebSession {
    /// `path` is the embed page the code is bound to, such as `/app/embed/threads/d0e515ef`.
    public static func webSession(path: String) -> Endpoint<WebSession> {
        Endpoint(method: .post, path: "web-session", body: WebSessionBody(path: path))
    }
}

/// The two requests that open the embedded thread page: where it lives, and the one that redeems a code for the session.
public enum EmbedRedeem {
    public static let redeemPath = "/app/embed/session"
    private static let maximumCodeLength = 128

    /// The embed page of a thread, or nil unless `threadID` is 8 letters or digits.
    public static func threadPath(_ threadID: String) -> String? {
        ThreadIdentifier.isValid(threadID) ? "/app/embed/threads/\(threadID)" : nil
    }

    /// The POST that redeems `session.code`: the form body is `code=<code>`. Nil, and nothing is sent, when the redeem
    /// address is not https (or http on 127.0.0.1 with `allowsLoopbackHTTP`), is not the instance's own host and port
    /// at the redeem path, carries credentials, a query or a fragment, or when the code is not made of letters,
    /// digits, "_" and "-".
    public static func redeemRequest(
        for session: WebSession, instanceURL: URL, allowsLoopbackHTTP: Bool = false
    ) -> URLRequest? {
        guard let components = URLComponents(string: session.redeemUrl), let url = components.url,
            let scheme = components.scheme?.lowercased(),
            scheme == "https" || (scheme == "http" && allowsLoopbackHTTP && components.host == "127.0.0.1"),
            components.user == nil, components.password == nil,
            components.query == nil, components.fragment == nil, components.path == redeemPath,
            let authority = PreviewNavigation.authority(of: url),
            authority == PreviewNavigation.authority(of: instanceURL),
            isValidCode(session.code)
        else { return nil }
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")
        request.httpBody = Data("code=\(session.code)".utf8)
        request.cachePolicy = .reloadIgnoringLocalCacheData
        return request
    }

    private static func isValidCode(_ code: String) -> Bool {
        !code.isEmpty && code.utf8.count <= maximumCodeLength
            && code.utf8.allSatisfy {
                ($0 >= 0x30 && $0 <= 0x39) || ($0 >= 0x41 && $0 <= 0x5A) || ($0 >= 0x61 && $0 <= 0x7A)
                    || $0 == UInt8(ascii: "_") || $0 == UInt8(ascii: "-")
            }
    }
}
