import Foundation

/// Which navigations the embedded thread page may make, and what happens to the others. The page lives on the
/// instance's own origin under `/app/embed/`; the one POST it may start is the redeem of a code.
public struct EmbedNavigationPolicy: Equatable, Sendable {
    public enum Decision: Equatable, Sendable {
        /// The web view goes there.
        case allow
        /// Nothing happens.
        case cancel
        /// The web view stays; the address passed `WebLinkRule` and opens in the app's browser.
        case openLink(URL)
        /// The web view stays; a link to a thread of this instance opens that thread in the app.
        case openThread(String)
        /// The web view stays; a shared file's page of this instance opens in the app's file viewer.
        case openFile(URL)
    }

    public static let embedPathPrefix = "/app/embed/"

    public let instanceURL: URL
    public let allowsLoopbackHTTP: Bool

    public init(instanceURL: URL, allowsLoopbackHTTP: Bool = false) {
        self.instanceURL = instanceURL
        self.allowsLoopbackHTTP = allowsLoopbackHTTP
    }

    /// The scheme, host and port are the instance's own, with a default port (443 for https) the same as none, and no
    /// user or password. Plain http counts only for 127.0.0.1 with `allowsLoopbackHTTP`.
    public func isInstanceOrigin(_ url: URL) -> Bool {
        guard let scheme = url.scheme?.lowercased(), let origin = Self.origin(of: url),
            origin == Self.origin(of: instanceURL)
        else { return false }
        return scheme == "https" || (scheme == "http" && allowsLoopbackHTTP && url.host() == "127.0.0.1")
    }

    private static func origin(of url: URL) -> String? {
        guard let components = URLComponents(url: url, resolvingAgainstBaseURL: false),
            let scheme = components.scheme?.lowercased(), let host = components.host, !host.isEmpty,
            components.user == nil, components.password == nil
        else { return nil }
        let defaultPort = ["https": 443, "http": 80][scheme]
        let port = components.port.flatMap { $0 == defaultPort ? nil : $0 }
        return "\(scheme)://\(host.lowercased())" + (port.map { ":\($0)" } ?? "")
    }

    /// The id in a link to one of this instance's threads: `/threads/` and eight letters or digits, with at most a
    /// fragment after it.
    public func threadID(in url: URL) -> String? {
        guard isInstanceOrigin(url), let components = URLComponents(url: url, resolvingAgainstBaseURL: false),
            components.query == nil,
            let match = components.percentEncodedPath.wholeMatch(of: /\/threads\/([A-Za-z0-9]{8})/)
        else { return nil }
        return String(match.1)
    }

    /// A link to the page of one shared file of this instance: `/files/` and 26 letters or digits, with no query and no
    /// fragment. The web view of the app is signed in for these pages, the browser sheet is not.
    public func isSharedFilePage(_ url: URL) -> Bool { sharedFile(url, allowsQuery: false)?.suffix == "" }

    /// The bytes of a shared file, which the file page frames: `/files/<id>/raw`, with no query and no fragment.
    public func isSharedFileRaw(_ url: URL) -> Bool { sharedFile(url, allowsQuery: false)?.suffix == "/raw" }

    /// The raw address with the one query the page's Download link uses.
    public func isSharedFileDownload(_ url: URL) -> Bool {
        URLComponents(url: url, resolvingAgainstBaseURL: false)?.query == "download=1"
            && sharedFile(url, allowsQuery: true)?.suffix == "/raw"
    }

    /// The id in a shared file address of any of the three forms.
    public func sharedFileID(of url: URL) -> String? {
        if isSharedFilePage(url) || isSharedFileRaw(url) || isSharedFileDownload(url) {
            return sharedFile(url, allowsQuery: true)?.id
        }
        return nil
    }

    /// The id and what follows it in a shared file address (`""` or `"/raw"`); nil for any other address.
    private func sharedFile(_ url: URL, allowsQuery: Bool) -> (id: String, suffix: String)? {
        guard isInstanceOrigin(url), let components = URLComponents(url: url, resolvingAgainstBaseURL: false),
            components.fragment == nil, allowsQuery || components.query == nil,
            let match = components.percentEncodedPath.wholeMatch(of: /\/files\/([A-Za-z0-9]{26})(|\/raw)/)
        else { return nil }
        return (String(match.1), String(match.2))
    }

    /// The origin of a script message's frame, as WebKit names it. A port of 0 is the scheme's default port.
    public func isInstanceOrigin(scheme: String, host: String, port: Int) -> Bool {
        var components = URLComponents()
        components.scheme = scheme
        components.host = host
        if port > 0 { components.port = port }
        return components.url.map(isInstanceOrigin) ?? false
    }

    /// A page of the embedded app on the instance's origin.
    public func isEmbedPage(_ url: URL) -> Bool {
        isInstanceOrigin(url) && url.path.hasPrefix(Self.embedPathPrefix) && !url.path.contains("/..")
    }

    /// `method` is the request's HTTP method; nil reads as GET. `isUserInitiated` is a tap on a link or a form.
    public func decide(url: URL?, method: String?, isMainFrame: Bool, isUserInitiated: Bool) -> Decision {
        guard let url else { return .cancel }
        guard isMainFrame else { return Self.isBlank(url) ? .allow : .cancel }
        let verb = (method ?? "GET").uppercased()
        if isInstanceOrigin(url) {
            if url.path == EmbedRedeem.redeemPath { return verb == "POST" && url.query == nil ? .allow : .cancel }
            if isEmbedPage(url) { return verb == "GET" ? .allow : .cancel }
        }
        guard isUserInitiated else { return .cancel }
        if let id = threadID(in: url) { return .openThread(id) }
        if isSharedFilePage(url), verb == "GET" { return .openFile(url) }
        guard let link = WebLinkRule.safeURL(url.absoluteString) else { return .cancel }
        return .openLink(link)
    }

    /// What a main-frame answer with `status` does: nil lets the page show; an outcome is given to the machine and
    /// the page is not shown, so an error page never replaces the conversation.
    public func outcome(
        ofResponseFrom url: URL, status: Int, isMainFrame: Bool
    ) -> WebConversationMachine.NavigationOutcome? {
        guard isMainFrame, isInstanceOrigin(url), status >= 400 else { return nil }
        return .http(status)
    }

    private static func isBlank(_ url: URL) -> Bool {
        url.scheme?.lowercased() == "about"
            && ["blank", "srcdoc"].contains(url.absoluteString.dropFirst(6).lowercased())
    }
}
