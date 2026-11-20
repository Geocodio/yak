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
    }

    public static let embedPathPrefix = "/app/embed/"

    public let instanceURL: URL
    public let allowsLoopbackHTTP: Bool

    public init(instanceURL: URL, allowsLoopbackHTTP: Bool = false) {
        self.instanceURL = instanceURL
        self.allowsLoopbackHTTP = allowsLoopbackHTTP
    }

    /// The scheme, host and port are the instance's own. Plain http counts only for 127.0.0.1 with `allowsLoopbackHTTP`.
    public func isInstanceOrigin(_ url: URL) -> Bool {
        guard let scheme = url.scheme?.lowercased(), let authority = PreviewNavigation.authority(of: url),
            authority == PreviewNavigation.authority(of: instanceURL), scheme == instanceURL.scheme?.lowercased()
        else { return false }
        return scheme == "https" || (scheme == "http" && allowsLoopbackHTTP && url.host() == "127.0.0.1")
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
        guard isUserInitiated, let link = WebLinkRule.safeURL(url.absoluteString) else { return .cancel }
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
