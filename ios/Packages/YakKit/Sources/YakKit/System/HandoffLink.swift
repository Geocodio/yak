import Foundation

/// The only thing a Handoff activity carries: the web's thread page.
public enum HandoffLink {
    /// `<instance>/threads/<id>`: the web's thread page. Nil for anything that is not a plain https instance
    /// address (http only with `allowsInsecure`, which test mode passes for the stub) or a valid thread id.
    public static func webURL(instance: URL, threadID: String, allowsInsecure: Bool = false) -> URL? {
        guard isValidThreadID(threadID),
            var components = URLComponents(url: instance, resolvingAgainstBaseURL: false),
            let scheme = components.scheme?.lowercased(),
            scheme == "https" || (allowsInsecure && scheme == "http"),
            let host = components.host, !host.isEmpty,
            components.user == nil, components.password == nil,
            components.query == nil, components.fragment == nil
        else {
            return nil
        }
        var path = components.path
        while path.hasSuffix("/") {
            path.removeLast()
        }
        components.path = path + "/threads/" + threadID
        return components.url
    }

    private static func isValidThreadID(_ id: String) -> Bool {
        id.utf8.count == 8
            && id.utf8.allSatisfy {
                ($0 >= 0x30 && $0 <= 0x39) || ($0 >= 0x41 && $0 <= 0x5A) || ($0 >= 0x61 && $0 <= 0x7A)
            }
    }
}
